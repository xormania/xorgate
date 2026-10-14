<?php

declare(strict_types=1);

namespace App\Service;

use App\Exception\RegistryConflictException;
use App\Exception\RegistryNotFoundException;
use App\Exception\IntakeCapacityException;
use App\Message\NormalizeReport;
use App\Report\JUnitAdapter;
use App\Report\ParsedReport;
use App\Report\ReportParseException;
use App\Report\ReportParseLimits;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Exception\RecoverableMessageHandlingException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Uid\Uuid;

/** Shared metadata commands; durable normalization is owned by the native Messenger worker. */
final class ReportIntake
{
    public function __construct(
        private readonly Connection $connection,
        private readonly ProjectRegistry $registry,
        private readonly ReportArtifactStore $store,
        private readonly MessageBusInterface $bus,
        private readonly IntakeLimits $limits,
        private readonly JUnitAdapter $adapter,
        private readonly LoggerInterface $logger,
    ) {
    }

    /** @param resource $stream */
    public function submit(string $projectId, string $executionId, array $metadata, $stream): array
    {
        [$projectId, $executionId, $input, $execution] = $this->context($projectId, $executionId, $metadata);
        $staged = $this->store->stage($stream, $this->limits->uploadMaximum());
        try {
            return $this->admit($projectId, $executionId, $input, $execution, $staged, null);
        } finally {
            $this->store->discard($staged);
        }
    }

    public function submitBase64(string $projectId, string $executionId, array $metadata, string $contentBase64): array
    {
        if (strlen($contentBase64) > 87384 || false === ($content = base64_decode($contentBase64, true)) || strlen($content) > 65536) {
            throw new \InvalidArgumentException('Inline content must be valid base64 decoding to at most 65536 bytes.');
        }
        $stream = fopen('php://temp', 'w+b');
        try {
            fwrite($stream, $content);
            rewind($stream);

            return $this->submit($projectId, $executionId, $metadata, $stream);
        } finally {
            fclose($stream);
        }
    }

    /** Larger clients may refer to already-retained originals without returning their bytes inline. */
    public function submitArtifact(string $projectId, string $executionId, array $metadata, string $artifactId): array
    {
        [$projectId, $executionId, $input, $execution] = $this->context($projectId, $executionId, $metadata);
        $artifactId = ProjectRegistry::canonicalId($artifactId);
        $artifact = $this->artifactRow($projectId, $executionId, $artifactId);
        return $this->admit($projectId, $executionId, $input, $execution,
            ['sha256' => $artifact['sha256'], 'bytes' => (int) $artifact['bytes']], $artifactId);
    }

    private function admit(string $projectId, string $executionId, array $input, array $execution, array $staged, ?string $artifactId): array
    {
        $hashInput = $input;
        unset($hashInput['request_id']);
        $digest = hash('sha256', self::json(['identity_version' => 1, 'input' => $hashInput,
            'sha256' => $staged['sha256'], 'bytes' => $staged['bytes']]));
        $operationId = $this->database(fn () => $this->connection->transactional(function () use ($projectId, $executionId, $input, $execution, $staged, $artifactId, $digest): string {
            $this->limits->lock();
            $old = $this->connection->fetchAssociative('SELECT id, request_digest FROM report_import WHERE project_id = ? AND execution_id = ? AND report_role = ? AND request_id = ?',
                [$projectId, $executionId, $input['report_role'], $input['request_id']]);
            if (false !== $old) {
                if (!hash_equals($old['request_digest'], $digest)) {
                    throw new RegistryConflictException('The report request identity was already used with different metadata or bytes.');
                }

                return $old['id'];
            }
            $this->limits->assertCapacity($staged['bytes'], $staged['sha256'], null === $artifactId);
            $now = self::now();
            if (null === $artifactId) {
                // Capacity is checked before publication. DB failure may leave a complete reusable blob.
                $this->store->publish($staged);
                $artifactId = Uuid::v7()->toRfc4122();
                $this->connection->insert('report_artifact', ['id' => $artifactId, 'project_id' => $projectId,
                    'execution_id' => $executionId, 'filename' => $input['filename'], 'media_type' => $input['media_type'],
                    'sha256' => $staged['sha256'], 'bytes' => $staged['bytes'], 'created_at' => $now]);
            } else {
                // Retention and admission share this lock; revalidate after acquiring it.
                $artifact = $this->artifactRow($projectId, $executionId, $artifactId);
                if (null !== $artifact['expired_at']) {
                    throw new RegistryConflictException('The original artifact has expired.');
                }
                $stream = $this->store->open($artifact['sha256'], (int) $artifact['bytes']);
                fclose($stream);
            }
            $id = Uuid::v7()->toRfc4122();
            $supported = $input['format'] === $this->adapter->format();
            $this->connection->insert('report_import', ['id' => $id, 'project_id' => $projectId, 'execution_id' => $executionId,
                'artifact_id' => $artifactId, 'request_id' => $input['request_id'], 'request_digest' => $digest,
                'report_role' => $input['report_role'], 'format' => $input['format'], 'source_context' => self::json($execution),
                'status' => 'queued', 'parser_name' => $supported ? $this->adapter->format() : null,
                'parser_version' => $supported ? $this->adapter->version() : null, 'created_at' => $now]);
            // Doctrine Messenger uses this connection: catalogue, operation and message commit together.
            $this->bus->dispatch(new NormalizeReport($id));

            return $id;
        }));

        return $this->getOperation($projectId, $executionId, $operationId);
    }

    public function getOperation(string $projectId, string $executionId, string $operationId): array
    {
        $row = $this->operationRow($projectId, $executionId, $operationId);

        return $this->operation($row);
    }

    public function listEvidence(string $projectId, string $executionId, int $limit = 25, ?string $cursor = null): array
    {
        $execution = $this->registry->getExecution($projectId, $executionId);
        self::limit($limit);
        $projectId = $execution['project_id'];
        $executionId = $execution['execution_id'];
        $cursor = null === $cursor ? null : ProjectRegistry::canonicalId($cursor);
        if (null !== $cursor) { $this->operationRow($projectId, $executionId, $cursor); }
        $rows = $this->database(fn () => $this->connection->fetchAllAssociative(
            'SELECT * FROM report_import WHERE project_id = ? AND execution_id = ?'.(null === $cursor ? '' : ' AND id > ?').' ORDER BY id LIMIT '.($limit + 1),
            null === $cursor ? [$projectId, $executionId] : [$projectId, $executionId, $cursor]));

        return self::page($rows, $limit, fn (array $row) => $this->operation($row), 'operation_id');
    }

    public function listObservations(string $projectId, string $executionId, string $operationId, int $limit = 25, ?string $cursor = null): array
    {
        $operation = $this->operationRow($projectId, $executionId, $operationId);
        self::limit($limit);
        $cursor = null === $cursor ? null : ProjectRegistry::canonicalId($cursor);
        if (null !== $cursor && false === $this->database(fn () => $this->connection->fetchOne('SELECT id FROM report_case WHERE operation_id = ? AND id = ?', [$operation['id'], $cursor]))) {
            throw new RegistryNotFoundException('The observation cursor was not found in this import.');
        }
        $rows = $this->database(fn () => $this->connection->fetchAllAssociative(
            'SELECT * FROM report_case WHERE operation_id = ?'.(null === $cursor ? '' : ' AND id > ?').' ORDER BY id LIMIT '.($limit + 1),
            null === $cursor ? [$operation['id']] : [$operation['id'], $cursor]));

        return self::page($rows, $limit, static fn (array $row): array => array_merge(self::decode($row['data']), [
            'observation_id' => $row['id'], 'operation_id' => $operation['id'], 'artifact_id' => $operation['artifact_id'],
            'project_id' => $operation['project_id'], 'execution_id' => $operation['execution_id'],
            'parser' => ['name' => $operation['parser_name'], 'version' => $operation['parser_version']],
        ]), 'observation_id');
    }

    public function getArtifact(string $projectId, string $executionId, string $artifactId): array
    {
        return $this->artifact($this->artifactRow($projectId, $executionId, $artifactId));
    }

    /** @return resource */
    public function openArtifact(string $projectId, string $executionId, string $artifactId)
    {
        $row = $this->artifactRow($projectId, $executionId, $artifactId);
        if (null !== $row['expired_at']) { throw new RegistryConflictException('The original artifact has expired.'); }

        return $this->store->open($row['sha256'], (int) $row['bytes']);
    }

    public function readArtifact(string $projectId, string $executionId, string $artifactId, int $offset = 0, int $limit = 32768): array
    {
        if ($offset < 0 || $offset > 2147483647 || $limit < 1 || $limit > 32768) {
            throw new \InvalidArgumentException('Artifact offsets must be nonnegative and chunks must be between 1 and 32768 bytes.');
        }
        $artifact = $this->getArtifact($projectId, $executionId, $artifactId);
        if ($offset > $artifact['bytes']) { throw new \InvalidArgumentException('The read offset is beyond the original artifact size.'); }
        $stream = $this->openArtifact($projectId, $executionId, $artifactId);
        try {
            if (0 !== fseek($stream, $offset) || false === ($content = fread($stream, $limit))) {
                throw new \RuntimeException('Original report bytes could not be read.');
            }
        } finally { fclose($stream); }
        $next = $offset + strlen($content);

        return ['schema_version' => 1, 'artifact' => $artifact, 'content_base64' => base64_encode($content),
            'offset' => $offset, 'returned_bytes' => strlen($content), 'next_offset' => $next < $artifact['bytes'] ? $next : null,
            'eof' => $next >= $artifact['bytes']];
    }

    /** Claim briefly, parse outside any transaction, then atomically commit all observations. */
    public function process(string $operationId): void
    {
        $operationId = ProjectRegistry::canonicalId($operationId);
        $claim = Uuid::v7()->toRfc4122();
        $row = $this->database(fn () => $this->connection->transactional(function () use ($operationId, $claim): array|false {
            $row = $this->connection->fetchAssociative('SELECT * FROM report_import WHERE id = ? FOR UPDATE', [$operationId]);
            if (false === $row) { throw new RegistryNotFoundException('The report import was not found.'); }
            if ('completed' === $row['status']) { return false; }
            if ('running' === $row['status'] && null !== $row['started_at'] && new \DateTimeImmutable($row['started_at']) > new \DateTimeImmutable('-5 minutes')) {
                $remaining = (new \DateTimeImmutable($row['started_at']))->modify('+5 minutes')->getTimestamp() - time();
                throw new RecoverableMessageHandlingException('The report import has an active processing lease.',
                    retryDelay: max(1, $remaining * 1000), forceRetry: false);
            }
            $this->connection->executeStatement("UPDATE report_import SET status = 'running', claim_token = ?, started_at = ?, error_code = NULL, error_message = NULL WHERE id = ?", [$claim, self::now(), $operationId]);

            return $row;
        }));
        if (false === $row) { return; }
        $started = hrtime(true);
        $this->processingLog('info', 'report.normalization_started', $row, $started);
        try {
            $artifact = $this->artifactRow($row['project_id'], $row['execution_id'], $row['artifact_id']);
            $availability = null === $artifact['expired_at'] ? $this->store->availability($artifact['sha256'], (int) $artifact['bytes']) : ['status' => 'expired'];
            if ('unavailable' === $availability['status']) { throw new \RuntimeException('Report storage is unavailable.'); }
            if ('available' !== $availability['status']) {
                $outcome = 'corrupt' === $availability['status'] ? 'corrupt' : 'missing';
                $this->finish($row, $claim, null, $outcome, 'report_'.$outcome, 'Original report bytes are '.$outcome.'.', $started);
                return;
            }
            if ($row['format'] !== $this->adapter->format()) {
                $this->finish($row, $claim, null, 'unsupported', 'report_unsupported', 'The report format has no installed adapter.', $started);
                return;
            }
            if ($row['parser_version'] !== $this->adapter->version()) {
                throw new \RuntimeException('The recorded parser version is not available in this worker.');
            }
            $stream = $this->store->open($artifact['sha256'], (int) $artifact['bytes']);
            try {
                $parsed = $this->adapter->parseStream($stream, new ReportParseLimits(maxBytes: $this->limits->uploadMaximum()));
            } finally { fclose($stream); }
            $this->finish($row, $claim, $parsed, $parsed->outcome, null, null, $started);
        } catch (ReportParseException $exception) {
            try {
                $this->finish($row, $claim, null, $exception->outcome, $exception->errorCode, $exception->getMessage(), $started);
            } catch (\Throwable) { $this->processingFailed($row, $claim, $started); }
        } catch (\Throwable) {
            $this->processingFailed($row, $claim, $started);
        }
    }

    private function processingFailed(array $operation, string $claim, int $started): never
    {
        try {
            $this->connection->executeStatement("UPDATE report_import SET status = 'failed', error_code = 'report_processing_unavailable', error_message = 'Report processing is unavailable. Inspect worker and storage readiness.' WHERE id = ? AND claim_token = ? AND status <> 'completed'", [$operation['id'], $claim]);
        } catch (\Throwable) { }
        $this->processingLog('error', 'report.normalization_unavailable', $operation, $started);
        throw new \RuntimeException('Report processing is unavailable. Inspect worker and storage readiness.');
    }

    private function finish(array $operation, string $claim, ?ParsedReport $parsed, string $outcome, ?string $errorCode, ?string $errorMessage, int $started): void
    {
        $completed = $this->database(fn () => $this->connection->transactional(function () use ($operation, $claim, $parsed, $outcome, $errorCode, $errorMessage): bool {
            $current = $this->connection->fetchAssociative('SELECT status, claim_token FROM report_import WHERE id = ? FOR UPDATE', [$operation['id']]);
            if (false === $current || 'completed' === $current['status'] || $claim !== $current['claim_token']) { return false; }
            if (null !== $parsed) {
                foreach ($parsed->suites as $suite) {
                    $this->connection->insert('report_suite', ['operation_id' => $operation['id'], 'suite_key' => $suite['suite_key'], 'data' => self::json($suite)]);
                }
                foreach ($parsed->cases as $case) {
                    $this->connection->insert('report_case', ['id' => Uuid::v7()->toRfc4122(), 'operation_id' => $operation['id'], 'case_key' => $case['case_key'], 'data' => self::json($case)]);
                }
            }
            $summary = null === $parsed ? null : ['cases' => count($parsed->cases), 'suites' => count($parsed->suites),
                'observed_counts' => $parsed->summary['observed_counts'], 'declared_counts' => $parsed->summary['declared_counts'],
                'retry_history' => $parsed->summary['retry_history'] ?? 'not_inferred'];
            $valid = null !== $parsed ? 'true' : (in_array($outcome, ['malformed', 'truncated', 'empty'], true) ? 'false' : null);
            $this->connection->executeStatement("UPDATE report_import SET status = 'completed', parser_outcome = ?, valid_document = ?, summary = ?, limitations = ?, error_code = ?, error_message = ?, completed_at = ?, claim_token = NULL WHERE id = ?",
                [$outcome, $valid, null === $summary ? null : self::json($summary), self::json($parsed?->limitations ?? []), $errorCode, $errorMessage, self::now(), $operation['id']]);
            return true;
        }));
        if ($completed) {
            $this->processingLog('info', 'report.normalization_completed', $operation, $started, $outcome, $parsed);
        }
    }

    private function processingLog(string $level, string $event, array $operation, int $started, ?string $outcome = null, ?ParsedReport $parsed = null): void
    {
        try {
            $this->logger->log($level, $event, ['correlation_id' => $operation['id'], 'operation_id' => $operation['id'],
                'project_id' => $operation['project_id'], 'execution_id' => $operation['execution_id'], 'artifact_id' => $operation['artifact_id'],
                'parser_outcome' => $outcome, 'cases' => null === $parsed ? null : count($parsed->cases),
                'suites' => null === $parsed ? null : count($parsed->suites), 'observed_counts' => $parsed?->summary['observed_counts'] ?? null,
                'elapsed_ms' => round((hrtime(true) - $started) / 1000000, 3)]);
        } catch (\Throwable) { /* Diagnostics never change committed evidence or worker retry semantics. */ }
    }

    public function capacity(): array { return $this->database(fn () => $this->limits->snapshot()); }

    /** Queue protection is conservative across all shared async/failed work, including probes. */
    public function cleanup(): array
    {
        return $this->database(fn () => $this->connection->transactional(function (): array {
            $this->limits->lock();
            if (0 !== (int) $this->connection->fetchOne("SELECT COUNT(*) FROM messenger_messages WHERE queue_name IN ('async', 'failed')")) {
                return ['schema_version' => 1, 'expired' => 0, 'pruned' => 0, 'orphan_blobs' => 0, 'staged_files' => 0, 'deferred' => 'queue_not_idle'];
            }
            $now = self::now();
            $artifactCutoff = self::cutoff($this->limits->artifactRetention());
            $recordCutoff = self::cutoff($this->limits->recordRetention());
            $eligible = $this->connection->fetchAllAssociative("SELECT a.* FROM report_artifact a WHERE a.expired_at IS NULL AND a.created_at < ? AND NOT EXISTS (SELECT 1 FROM report_import i WHERE i.artifact_id = a.id AND (i.status <> 'completed' OR i.completed_at >= ?)) ORDER BY a.created_at, a.id LIMIT 100 FOR UPDATE", [$artifactCutoff, $artifactCutoff]);
            foreach ($eligible as $artifact) {
                $this->connection->executeStatement('UPDATE report_artifact SET expired_at = ? WHERE id = ?', [$now, $artifact['id']]);
                if (0 === (int) $this->connection->fetchOne('SELECT COUNT(*) FROM report_artifact WHERE sha256 = ? AND expired_at IS NULL', [$artifact['sha256']])) {
                    $this->store->remove($artifact['sha256']);
                }
            }
            $pruned = $this->connection->fetchFirstColumn("SELECT id FROM report_import WHERE status = 'completed' AND completed_at < ? ORDER BY completed_at, id LIMIT 100 FOR UPDATE", [$recordCutoff]);
            foreach ($pruned as $id) { $this->connection->delete('report_import', ['id' => $id]); }
            $unused = $this->connection->fetchFirstColumn('SELECT a.id FROM report_artifact a WHERE a.expired_at IS NOT NULL AND NOT EXISTS (SELECT 1 FROM report_import i WHERE i.artifact_id = a.id) ORDER BY a.id LIMIT 100');
            foreach ($unused as $id) { $this->connection->delete('report_artifact', ['id' => $id]); }
            $hashes = $this->connection->fetchFirstColumn('SELECT DISTINCT sha256 FROM report_artifact WHERE expired_at IS NULL');
            $orphans = $this->store->cleanupOrphans($hashes, time() - 3600);

            return array_merge(['schema_version' => 1, 'expired' => count($eligible), 'pruned' => count($pruned), 'deferred' => null], $orphans);
        }));
    }

    private function context(string $projectId, string $executionId, array $metadata): array
    {
        $allowed = ['schema_version', 'request_id', 'report_role', 'format', 'filename', 'media_type', 'source'];
        foreach (array_keys($metadata) as $key) {
            if (!is_string($key) || !in_array($key, $allowed, true)) { throw new \InvalidArgumentException('Unexpected report metadata field.'); }
        }
        if (array_key_exists('schema_version', $metadata) && 1 !== $metadata['schema_version']) { throw new \InvalidArgumentException('schema_version must be 1.'); }
        if (!isset($metadata['request_id']) || !is_string($metadata['request_id'])) { throw new \InvalidArgumentException('request_id must be a UUID.'); }
        if (!isset($metadata['source']) || !is_array($metadata['source'])) { throw new \InvalidArgumentException('source must contain a known execution context assertion.'); }
        $execution = $this->registry->assertExecutionContext($projectId, $executionId, $metadata['source']);
        $source = [];
        $keys = array_keys($metadata['source']);
        sort($keys);
        foreach ($keys as $key) { $source[$key] = $execution['source'][$key]; }
        $role = self::text($metadata['report_role'] ?? 'junit', 80, 'report_role');
        $format = self::text($metadata['format'] ?? 'junit', 80, 'format');
        if (null === $role || null === $format) { throw new \InvalidArgumentException('report_role and format must be nonempty strings.'); }
        $input = ['schema_version' => 1, 'request_id' => ProjectRegistry::canonicalId($metadata['request_id']),
            'report_role' => $role, 'format' => $format, 'filename' => self::text($metadata['filename'] ?? null, 255, 'filename'),
            'media_type' => self::text($metadata['media_type'] ?? null, 120, 'media_type'), 'source' => $source];

        return [$execution['project_id'], $execution['execution_id'], $input, $execution];
    }

    private function operationRow(string $projectId, string $executionId, string $operationId): array
    {
        $execution = $this->registry->getExecution($projectId, $executionId);
        $operationId = ProjectRegistry::canonicalId($operationId);
        $row = $this->database(fn () => $this->connection->fetchAssociative('SELECT * FROM report_import WHERE project_id = ? AND execution_id = ? AND id = ?', [$execution['project_id'], $execution['execution_id'], $operationId]));
        if (false === $row) { throw new RegistryNotFoundException('The report import was not found in this execution.'); }

        return $row;
    }

    private function artifactRow(string $projectId, string $executionId, string $artifactId): array
    {
        $execution = $this->registry->getExecution($projectId, $executionId);
        $artifactId = ProjectRegistry::canonicalId($artifactId);
        $row = $this->database(fn () => $this->connection->fetchAssociative('SELECT * FROM report_artifact WHERE project_id = ? AND execution_id = ? AND id = ?', [$execution['project_id'], $execution['execution_id'], $artifactId]));
        if (false === $row) { throw new RegistryNotFoundException('The original artifact was not found in this execution.'); }

        return $row;
    }

    private function artifact(array $row): array
    {
        return ['schema_version' => 1, 'artifact_id' => $row['id'], 'project_id' => $row['project_id'], 'execution_id' => $row['execution_id'],
            'filename' => $row['filename'], 'media_type' => $row['media_type'], 'sha256' => $row['sha256'], 'bytes' => (int) $row['bytes'],
            'created_at' => self::iso($row['created_at']), 'expired_at' => null === $row['expired_at'] ? null : self::iso($row['expired_at']),
            'availability' => null === $row['expired_at'] ? $this->store->availability($row['sha256'], (int) $row['bytes']) : ['status' => 'expired', 'available' => false]];
    }

    private function operation(array $row): array
    {
        return ['schema_version' => 1, 'operation_id' => $row['id'], 'correlation_id' => $row['id'], 'project_id' => $row['project_id'], 'execution_id' => $row['execution_id'],
            'request_id' => $row['request_id'], 'report_role' => $row['report_role'], 'format' => $row['format'], 'status' => $row['status'],
            'parser_outcome' => $row['parser_outcome'], 'valid_document' => null === $row['valid_document'] ? null : in_array($row['valid_document'], [true, 1, '1', 't', 'true'], true),
            'artifact' => $this->getArtifact($row['project_id'], $row['execution_id'], $row['artifact_id']),
            'parser' => ['name' => $row['parser_name'], 'version' => $row['parser_version']], 'source_context' => self::decode($row['source_context']),
            'created_at' => self::iso($row['created_at']), 'started_at' => null === $row['started_at'] ? null : self::iso($row['started_at']),
            'completed_at' => null === $row['completed_at'] ? null : self::iso($row['completed_at']),
            'summary' => null === $row['summary'] ? ['cases' => null, 'suites' => null, 'observed_counts' => null, 'declared_counts' => null] : self::decode($row['summary']),
            'limitations' => self::decode($row['limitations']), 'error' => null === $row['error_code'] ? null : ['code' => $row['error_code'], 'message' => $row['error_message']]];
    }

    private function database(callable $operation): mixed
    {
        try { return $operation(); }
        catch (\InvalidArgumentException|RegistryConflictException|RegistryNotFoundException|IntakeCapacityException|RecoverableMessageHandlingException $exception) { throw $exception; }
        catch (\Throwable) { throw new \RuntimeException('Report intake data is unavailable. Verify database and storage readiness.'); }
    }

    private static function text(mixed $value, int $maximum, string $field): ?string
    {
        if (null === $value) { return null; }
        if (!is_string($value) || strlen($value) > $maximum * 4 || !preg_match('//u', $value)
            || preg_match('/[\x00-\x1f\x7f]/u', $value) || iconv_strlen($value, 'UTF-8') > $maximum) {
            throw new \InvalidArgumentException($field.' must be bounded text without control characters.');
        }
        $value = preg_replace('/\A[\s\p{Z}\x{0085}]+|[\s\p{Z}\x{0085}]+\z/u', '', $value);
        foreach (preg_split('//u', $value, -1, PREG_SPLIT_NO_EMPTY) as $character) {
            if (!\IntlChar::isUWhiteSpace($character) && !\IntlChar::isIDIgnorable($character)
                && !\IntlChar::hasBinaryProperty($character, \IntlChar::PROPERTY_DEFAULT_IGNORABLE_CODE_POINT)) { return $value; }
        }

        return null;
    }

    private static function limit(int $limit): void { if ($limit < 1 || $limit > 100) { throw new \InvalidArgumentException('limit must be between 1 and 100.'); } }
    private static function page(array $rows, int $limit, callable $map, string $key): array
    {
        $more = count($rows) > $limit;
        $items = array_map($map, array_slice($rows, 0, $limit));
        return ['schema_version' => 1, 'items' => $items, 'next_cursor' => $more ? $items[array_key_last($items)][$key] : null, 'limit' => $limit];
    }
    private static function json(array $value): string { return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); }
    private static function decode(string $value): array { return json_decode($value, true, 32, JSON_THROW_ON_ERROR); }
    private static function now(): string { return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.uP'); }
    private static function iso(string $value): string { return (new \DateTimeImmutable($value))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.uP'); }
    private static function cutoff(int $seconds): string { return (new \DateTimeImmutable('-'.$seconds.' seconds', new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.uP'); }
}
