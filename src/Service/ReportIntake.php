<?php

declare(strict_types=1);

namespace App\Service;

use App\Exception\RegistryConflictException;
use App\Exception\RegistryNotFoundException;
use App\Exception\IntakeCapacityException;
use App\Message\NormalizeReport;
use App\Report\ParsedReport;
use App\Report\ReportAdapterInterface;
use App\Report\ReportParseException;
use App\Report\ReportParseLimits;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Component\Messenger\Exception\RecoverableMessageHandlingException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Uid\Uuid;

/** Shared metadata commands; durable normalization is owned by the native Messenger worker. */
final class ReportIntake
{
    /** Annotation kinds that refer to one native attempt or one native case. Others describe the run itself. */
    private const ATTEMPT_KINDS = ['failed_attempt', 'jev_assessment'];
    private const CASE_KINDS = ['test_duration'];
    private const NATIVE_FORMAT = 'playwright-json';
    public const LOG_WINDOW_MAXIMUM = 32768;

    /** @var array<string, ReportAdapterInterface> */
    private readonly array $adapters;

    /** @param iterable<ReportAdapterInterface> $adapters */
    public function __construct(
        private readonly Connection $connection,
        private readonly ProjectRegistry $registry,
        private readonly ReportArtifactStore $store,
        private readonly MessageBusInterface $bus,
        private readonly IntakeLimits $limits,
        #[AutowireIterator('app.report_adapter')] iterable $adapters,
        private readonly LoggerInterface $logger,
    ) {
        $installed = [];
        foreach ($adapters as $adapter) {
            if (!$adapter instanceof ReportAdapterInterface || isset($installed[$adapter->format()])) {
                throw new \LogicException('Report adapters must implement the adapter contract with distinct formats.');
            }
            $installed[$adapter->format()] = $adapter;
        }
        ksort($installed);
        $this->adapters = $installed;
    }

    /** @return array<string, string> Installed adapter formats and versions, sorted by format. */
    public function formats(): array
    {
        return array_map(static fn (ReportAdapterInterface $adapter): string => $adapter->version(), $this->adapters);
    }

    /** @param resource $stream */
    public function submit(string $projectId, string $executionId, array $metadata, $stream): array
    {
        [$projectId, $executionId, $input, $execution] = $this->context($projectId, $executionId, $metadata);
        // Replays verify all supplied bytes without consuming another disk reservation.
        $old = $this->database(fn () => $this->connection->fetchOne('SELECT id FROM report_import WHERE project_id = ? AND execution_id = ? AND report_role = ? AND request_id = ?',
            [$projectId, $executionId, $input['report_role'], $input['request_id']]));
        if (false !== $old) {
            return $this->admit($projectId, $executionId, $input, $execution, $this->fingerprint($stream), null, true);
        }
        try {
            $staged = $this->store->stage($stream, $this->limits->uploadMaximum(), $this->limits->storageMaximum(), $this->limits->fileMaximum());
        } catch (IntakeCapacityException $exception) {
            // The store knows only filesystem usage. Public adapters require the full
            // current installation snapshot, with the rejected reservation kept distinct.
            $capacity = $this->capacity();
            $capacity['status'] = 'limited';
            $capacity['reason'] = $exception->capacity['reason'];
            $capacity['retry_after_seconds'] = null;
            $capacity['rejected_reservation'] = ['measurement_scope' => 'physical_storage', 'usage' => $exception->capacity['usage']];
            throw new IntakeCapacityException($capacity);
        }
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

    private function admit(string $projectId, string $executionId, array $input, array $execution, array $staged, ?string $artifactId, bool $replayOnly = false): array
    {
        $hashInput = $input;
        unset($hashInput['request_id']);
        $digest = hash('sha256', self::json(['identity_version' => 1, 'input' => $hashInput,
            'sha256' => $staged['sha256'], 'bytes' => $staged['bytes']]));
        $operationId = $this->database(fn () => $this->connection->transactional(function () use ($projectId, $executionId, $input, $execution, $staged, $artifactId, $digest, $replayOnly): string {
            $this->limits->lock();
            $old = $this->connection->fetchAssociative('SELECT id, request_digest FROM report_import WHERE project_id = ? AND execution_id = ? AND report_role = ? AND request_id = ?',
                [$projectId, $executionId, $input['report_role'], $input['request_id']]);
            if (false !== $old) {
                if (!hash_equals($old['request_digest'], $digest)) {
                    throw new RegistryConflictException('The report request identity was already used with different metadata or bytes.');
                }

                return $old['id'];
            }
            if ($replayOnly) {
                throw new RegistryNotFoundException('The report request is no longer retained. Submit a new request identity.');
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
            $adapter = $this->adapters[$input['format']] ?? null;
            $this->connection->insert('report_import', ['id' => $id, 'project_id' => $projectId, 'execution_id' => $executionId,
                'artifact_id' => $artifactId, 'request_id' => $input['request_id'], 'request_digest' => $digest,
                'report_role' => $input['report_role'], 'format' => $input['format'], 'source_context' => self::json($execution),
                'status' => 'queued', 'parser_name' => $adapter?->format(),
                'parser_version' => $adapter?->version(), 'created_at' => $now]);
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

        return self::page($rows, $limit, fn (array $row) => $this->operation($row, false), 'operation_id');
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

        $references = self::NATIVE_FORMAT === $operation['format'] ? $this->annotationReferences($operation['id'], 'target_case_key', array_column($rows, 'case_key')) : [];

        return self::page($rows, $limit, static fn (array $row): array => array_merge(self::decode($row['data']), [
            'observation_id' => $row['id'], 'operation_id' => $operation['id'], 'artifact_id' => $operation['artifact_id'],
            'project_id' => $operation['project_id'], 'execution_id' => $operation['execution_id'],
            'parser' => ['name' => $operation['parser_name'], 'version' => $operation['parser_version']],
        ], self::NATIVE_FORMAT === $operation['format'] ? ['annotations' => $references[$row['case_key']]['items'] ?? [],
            'annotation_count' => $references[$row['case_key']]['count'] ?? 0] : []), 'observation_id');
    }

    /** Native attempts in report order, each with bounded references to derived annotations linked to it. */
    public function listAttempts(string $projectId, string $executionId, string $operationId, int $limit = 25, ?string $cursor = null): array
    {
        $operation = $this->operationRow($projectId, $executionId, $operationId);
        self::limit($limit);
        $cursor = null === $cursor ? null : ProjectRegistry::canonicalId($cursor);
        if (null !== $cursor && false === $this->database(fn () => $this->connection->fetchOne('SELECT id FROM report_attempt WHERE operation_id = ? AND id = ?', [$operation['id'], $cursor]))) {
            throw new RegistryNotFoundException('The attempt cursor was not found in this import.');
        }
        $rows = $this->database(fn () => $this->connection->fetchAllAssociative(
            'SELECT a.id, a.case_key, a.attempt_key, a.data, c.data AS case_data FROM report_attempt a LEFT JOIN report_case c ON c.operation_id = a.operation_id AND c.case_key = a.case_key WHERE a.operation_id = ?'
            .(null === $cursor ? '' : ' AND a.id > ?').' ORDER BY a.id LIMIT '.($limit + 1),
            null === $cursor ? [$operation['id']] : [$operation['id'], $cursor]));
        $references = $this->annotationReferences($operation['id'], 'target_attempt_key', array_column(array_slice($rows, 0, $limit), 'attempt_key'));

        return self::page($rows, $limit, static function (array $row) use ($operation, $references): array {
            $case = null === $row['case_data'] ? [] : self::decode($row['case_data']);
            $context = [];
            foreach (['name', 'title_path', 'full_title', 'file', 'line', 'column', 'project', 'project_id', 'spec_id', 'test_id', 'expected_status', 'native_outcome', 'expected_failure'] as $field) {
                $context[$field] = $case[$field] ?? null;
            }

            return array_merge(self::decode($row['data']), [
                'attempt_id' => $row['id'], 'operation_id' => $operation['id'], 'artifact_id' => $operation['artifact_id'],
                'project_id' => $operation['project_id'], 'execution_id' => $operation['execution_id'],
                'parser' => ['name' => $operation['parser_name'], 'version' => $operation['parser_version']],
                'case' => $context,
                'annotations' => $references[$row['attempt_key']]['items'] ?? [],
                'annotation_count' => $references[$row['attempt_key']]['count'] ?? 0,
            ]);
        }, 'attempt_id');
    }

    /** Annotations produced by this import, or linked to it as native evidence, with their explicit link state. */
    public function listAnnotations(string $projectId, string $executionId, string $operationId, int $limit = 25, ?string $cursor = null): array
    {
        $operation = $this->operationRow($projectId, $executionId, $operationId);
        self::limit($limit);
        $cursor = null === $cursor ? null : ProjectRegistry::canonicalId($cursor);
        if (null !== $cursor && false === $this->database(fn () => $this->connection->fetchOne('SELECT id FROM report_annotation WHERE id = ? AND (operation_id = ? OR target_operation_id = ?)', [$cursor, $operation['id'], $operation['id']]))) {
            throw new RegistryNotFoundException('The annotation cursor was not found for this import.');
        }
        $rows = $this->database(fn () => $this->connection->fetchAllAssociative(
            'SELECT n.*, i.artifact_id, i.parser_name, i.parser_version FROM report_annotation n JOIN report_import i ON i.id = n.operation_id WHERE (n.operation_id = ? OR n.target_operation_id = ?)'
            .(null === $cursor ? '' : ' AND n.id > ?').' ORDER BY n.id LIMIT '.($limit + 1),
            null === $cursor ? [$operation['id'], $operation['id']] : [$operation['id'], $operation['id'], $cursor]));

        return self::page($rows, $limit, static fn (array $row): array => [
            'annotation_id' => $row['id'], 'operation_id' => $row['operation_id'], 'artifact_id' => $row['artifact_id'],
            'project_id' => $row['project_id'], 'execution_id' => $row['execution_id'],
            'relation' => $row['operation_id'] === $operation['id'] ? 'produced' : 'linked_to_native',
            'parser' => ['name' => $row['parser_name'], 'version' => $row['parser_version']],
            'annotation_key' => $row['annotation_key'], 'kind' => $row['kind'],
            'target' => self::decode($row['target']), 'data' => self::decode($row['data']),
            'match' => ['status' => $row['match_status'], 'reason' => $row['match_reason'],
                'target_operation_id' => $row['target_operation_id'], 'target_case_key' => $row['target_case_key'],
                'target_attempt_key' => $row['target_attempt_key'], 'linked_at' => null === $row['linked_at'] ? null : self::iso($row['linked_at'])],
        ], 'annotation_id');
    }

    /** @return array<string, array{items: list<array>, count: int}> */
    private function annotationReferences(string $operationId, string $column, array $keys): array
    {
        if ([] === $keys) { return []; }
        $rows = $this->database(fn () => $this->connection->fetchAllAssociative(
            'SELECT * FROM (SELECT id, operation_id, kind, match_status, '.$column.' AS target_key, COUNT(*) OVER (PARTITION BY '.$column.') AS total, ROW_NUMBER() OVER (PARTITION BY '.$column.' ORDER BY id) AS position FROM report_annotation WHERE target_operation_id = ? AND '.$column.' IN (?)) refs WHERE position <= 20 ORDER BY target_key, id',
            [$operationId, array_values(array_unique($keys))], [1 => ArrayParameterType::STRING]));
        $references = [];
        foreach ($rows as $row) {
            $references[$row['target_key']]['count'] = (int) $row['total'];
            $references[$row['target_key']]['items'][] = ['annotation_id' => $row['id'], 'operation_id' => $row['operation_id'], 'kind' => $row['kind'], 'match_status' => $row['match_status']];
        }

        return $references;
    }

    /**
     * A bounded, verified text window over retained bytes. Offsets are byte offsets into the original;
     * content is decoded as UTF-8 with replacement characters, flagged rather than hidden.
     */
    public function readLogWindow(string $projectId, string $executionId, string $artifactId, int $offset = 0, int $limit = self::LOG_WINDOW_MAXIMUM): array
    {
        if ($offset < 0 || $offset > 2147483647 || $limit < 1 || $limit > self::LOG_WINDOW_MAXIMUM) {
            throw new \InvalidArgumentException('Log offsets must be nonnegative and windows must be between 1 and 32768 bytes.');
        }
        $artifact = $this->getArtifact($projectId, $executionId, $artifactId);
        if ($offset > $artifact['bytes']) { throw new \InvalidArgumentException('The log offset is beyond the original artifact size.'); }
        $stream = $this->openArtifact($projectId, $executionId, $artifactId);
        try {
            // One preceding byte tells whether the window starts at a line boundary.
            $start = max(0, $offset - 1);
            // Three spare bytes let a window that starts a multibyte character still return that whole character.
            if (0 !== fseek($stream, $start) || false === ($bytes = fread($stream, $limit + 3 + ($offset - $start)))) {
                throw new \RuntimeException('Original log bytes could not be read.');
            }
        } finally { fclose($stream); }
        $previous = $offset > $start ? substr($bytes, 0, 1) : null;
        $available = $offset > $start ? substr($bytes, 1) : $bytes;
        $content = substr($available, 0, $limit);
        $end = $offset + strlen($content);
        if ($end < $artifact['bytes']) {
            // Do not split a UTF-8 sequence at the window end; the next window starts at its lead byte. When the
            // whole window is the start of one character, return that character (at most 4 bytes) instead of nothing.
            $tail = self::incompleteUtf8Tail($content);
            if ($tail === strlen($content)) {
                $content = substr($available, 0, min(strlen($available), self::utf8SequenceLength($available[0])));
            } else {
                $content = substr($content, 0, strlen($content) - $tail);
            }
            $end = $offset + strlen($content);
        }
        $eof = $end >= $artifact['bytes'];
        $valid = mb_check_encoding($content, 'UTF-8');
        if ($valid) {
            $text = $content;
        } else {
            $substitute = mb_substitute_character();
            mb_substitute_character(0xFFFD);
            try { $text = mb_scrub($content, 'UTF-8'); } finally { mb_substitute_character($substitute); }
        }

        return ['schema_version' => 1, 'artifact' => $artifact, 'offset' => $offset, 'returned_bytes' => strlen($content),
            'next_offset' => $eof ? null : $end, 'previous_offset' => 0 === $offset ? null : max(0, $offset - $limit), 'eof' => $eof,
            'starts_mid_line' => null !== $previous && "\n" !== $previous,
            'ends_mid_line' => !$eof && '' !== $content && "\n" !== $content[strlen($content) - 1],
            'utf8_lossy' => !$valid, 'content_text' => $text];
    }

    /** Bytes at the end of $content that begin a UTF-8 sequence it does not complete (0 to strlen($content)). */
    private static function incompleteUtf8Tail(string $content): int
    {
        $length = strlen($content);
        for ($back = 1; $back <= min(3, $length); ++$back) {
            $byte = ord($content[$length - $back]);
            if (0x80 === ($byte & 0xC0)) { continue; }
            $needed = self::utf8SequenceLength($content[$length - $back]);

            return $needed > $back ? $back : 0;
        }

        return 0;
    }

    /** Declared length of the UTF-8 sequence a lead byte starts; continuation and invalid bytes count as 1. */
    private static function utf8SequenceLength(string $lead): int
    {
        $byte = ord($lead);

        return match (true) { 0xC0 === ($byte & 0xE0) => 2, 0xE0 === ($byte & 0xF0) => 3, 0xF0 === ($byte & 0xF8) => 4, default => 1 };
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
            $adapter = $this->adapters[$row['format']] ?? null;
            if (null === $adapter || null === $row['parser_name']) {
                $this->finish($row, $claim, null, 'unsupported', 'report_unsupported', 'The report format has no installed adapter.', $started);
                return;
            }
            if ($row['parser_version'] !== $adapter->version()) {
                throw new \RuntimeException('The recorded parser version is not available in this worker.');
            }
            $stream = $this->store->open($artifact['sha256'], (int) $artifact['bytes']);
            try {
                $parsed = $adapter->parseStream($stream, new ReportParseLimits(maxBytes: $this->limits->uploadMaximum()));
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
        $completed = $this->database(fn () => $this->connection->transactional(function () use ($operation, $claim, $parsed, $outcome, $errorCode, $errorMessage, $started): bool {
            $current = $this->connection->fetchAssociative('SELECT status, claim_token FROM report_import WHERE id = ? FOR UPDATE', [$operation['id']]);
            if (false === $current || 'completed' === $current['status'] || $claim !== $current['claim_token']) { return false; }
            $attempts = $parsed?->attempts ?? [];
            $annotations = $parsed?->annotations ?? [];
            $native = null !== $parsed && self::NATIVE_FORMAT === $operation['format'] && in_array($outcome, ['parsed', 'partial'], true);
            if ($native || [] !== $annotations) {
                // Native and derived imports of one execution link under one execution-scoped lock, so
                // concurrently completing imports always observe each other's committed rows.
                $this->linkLock($operation['execution_id']);
            }
            if (null !== $parsed) {
                foreach ($parsed->suites as $suite) {
                    $this->connection->insert('report_suite', ['operation_id' => $operation['id'], 'suite_key' => $suite['suite_key'], 'data' => self::json($suite)]);
                }
                foreach ($parsed->cases as $case) {
                    $this->connection->insert('report_case', ['id' => Uuid::v7()->toRfc4122(), 'operation_id' => $operation['id'], 'case_key' => $case['case_key'], 'data' => self::json($case)]);
                }
                foreach ($attempts as $attempt) {
                    $this->connection->insert('report_attempt', ['id' => Uuid::v7()->toRfc4122(), 'operation_id' => $operation['id'],
                        'case_key' => $attempt['case_key'], 'attempt_key' => $attempt['attempt_key'],
                        'retry' => is_int($attempt['retry'] ?? null) ? $attempt['retry'] : null, 'data' => self::json($attempt)]);
                }
                foreach ($annotations as $annotation) {
                    $kind = (string) $annotation['kind'];
                    $linkable = in_array($kind, self::ATTEMPT_KINDS, true) || in_array($kind, self::CASE_KINDS, true);
                    $this->connection->insert('report_annotation', ['id' => Uuid::v7()->toRfc4122(), 'operation_id' => $operation['id'],
                        'project_id' => $operation['project_id'], 'execution_id' => $operation['execution_id'],
                        'annotation_key' => $annotation['annotation_key'], 'kind' => $kind,
                        'target' => self::json(is_array($annotation['target'] ?? null) ? $annotation['target'] : []),
                        'data' => self::json(is_array($annotation['data'] ?? null) ? $annotation['data'] : []),
                        'match_status' => $linkable ? 'unmatched' : 'not_applicable',
                        'match_reason' => $linkable ? 'native_report_not_imported' : null]);
                }
            }
            $summary = null === $parsed ? null : array_merge($parsed->summary, ['cases' => count($parsed->cases), 'suites' => count($parsed->suites),
                'attempts' => count($attempts), 'annotations' => count($annotations),
                'observed_counts' => $parsed->summary['observed_counts'] ?? null, 'declared_counts' => $parsed->summary['declared_counts'] ?? null,
                'retry_history' => $parsed->summary['retry_history'] ?? 'not_inferred']);
            $valid = null !== $parsed ? 'true' : (in_array($outcome, ['malformed', 'truncated', 'empty'], true) ? 'false' : null);
            // Worker wall time from claim to this completing statement: parse plus observation writes.
            $elapsed = max(0, (int) round((hrtime(true) - $started) / 1000000));
            $this->connection->executeStatement("UPDATE report_import SET status = 'completed', parser_outcome = ?, valid_document = ?, summary = ?, limitations = ?, error_code = ?, error_message = ?, completed_at = ?, processing_ms = ?, claim_token = NULL WHERE id = ?",
                [$outcome, $valid, null === $summary ? null : self::json($summary), self::json($parsed?->limitations ?? []), $errorCode, $errorMessage, self::now(), min($elapsed, 2147483647), $operation['id']]);
            // Linking reads native rows only. It never inserts attempts or cases, so derived files cannot double count.
            if ($native) {
                $this->linkAnnotations($operation['project_id'], $operation['execution_id'], null);
            } elseif ([] !== $annotations) {
                $this->linkAnnotations($operation['project_id'], $operation['execution_id'], $operation['id']);
            }
            return true;
        }));
        if ($completed) {
            $this->processingLog('info', 'report.normalization_completed', $operation, $started, $outcome, $parsed);
        }
    }

    private function linkLock(string $executionId): void
    {
        $this->connection->executeQuery('SELECT pg_advisory_xact_lock(hashtextextended(CAST(? AS text), 3))', ['report_annotation_link:'.$executionId]);
    }

    /** Re-evaluate link-eligible annotations of one execution, or of one annotation import, against completed native imports. */
    private function linkAnnotations(string $projectId, string $executionId, ?string $annotationOperationId, string $absentReason = 'native_report_not_imported'): void
    {
        $kinds = array_merge(self::ATTEMPT_KINDS, self::CASE_KINDS);
        $rows = $this->connection->fetchAllAssociative('SELECT id, kind, target, match_status, match_reason, target_operation_id, target_case_key, target_attempt_key FROM report_annotation WHERE project_id = ? AND execution_id = ? AND kind IN (?)'
            .(null === $annotationOperationId ? '' : ' AND operation_id = ?').' ORDER BY id',
            null === $annotationOperationId ? [$projectId, $executionId, $kinds] : [$projectId, $executionId, $kinds, $annotationOperationId],
            [2 => ArrayParameterType::STRING]);
        if ([] === $rows) { return; }
        $natives = (int) $this->connection->fetchOne("SELECT COUNT(*) FROM report_import WHERE project_id = ? AND execution_id = ? AND status = 'completed' AND format = ? AND parser_outcome IN ('parsed', 'partial')",
            [$projectId, $executionId, self::NATIVE_FORMAT]);
        $now = self::now();
        foreach ($rows as $row) {
            $result = $this->matchAnnotation($projectId, $executionId, $row['kind'], self::decode($row['target']), $natives, $absentReason);
            if ($result['status'] === $row['match_status'] && $result['reason'] === $row['match_reason'] && $result['operation_id'] === $row['target_operation_id']
                && $result['case_key'] === $row['target_case_key'] && $result['attempt_key'] === $row['target_attempt_key']) {
                continue;
            }
            $this->connection->executeStatement('UPDATE report_annotation SET match_status = ?, match_reason = ?, target_operation_id = ?, target_case_key = ?, target_attempt_key = ?, linked_at = ? WHERE id = ?',
                [$result['status'], $result['reason'], $result['operation_id'], $result['case_key'], $result['attempt_key'], 'linked' === $result['status'] ? $now : null, $row['id']]);
        }
    }

    /** @return array{status: string, reason: ?string, operation_id: ?string, case_key: ?string, attempt_key: ?string} */
    private function matchAnnotation(string $projectId, string $executionId, string $kind, array $target, int $natives, string $absentReason): array
    {
        $none = static fn (string $status, string $reason): array => ['status' => $status, 'reason' => $reason, 'operation_id' => null, 'case_key' => null, 'attempt_key' => null];
        if (0 === $natives) { return $none('unmatched', $absentReason); }
        $attemptLevel = in_array($kind, self::ATTEMPT_KINDS, true);
        $conditions = ['i.project_id = ?', 'i.execution_id = ?', "i.status = 'completed'", 'i.format = ?', "i.parser_outcome IN ('parsed', 'partial')"];
        $parameters = [$projectId, $executionId, self::NATIVE_FORMAT];
        $project = self::scalarText($target['project'] ?? null);
        if (null !== $project) { $conditions[] = "c.data->>'project' = ?"; $parameters[] = $project; }
        if ($attemptLevel) {
            $spec = self::scalarText($target['spec_id'] ?? null);
            $test = self::scalarText($target['test_id'] ?? null);
            $retry = $target['retry'] ?? null;
            if ((null === $spec && null === $test) || !is_int($retry)) { return $none('unmatched', 'target_incomplete'); }
            if (null !== $spec) { $conditions[] = "c.data->>'spec_id' = ?"; $parameters[] = $spec; }
            else { $conditions[] = "c.data->>'test_id' = ?"; $parameters[] = $test; }
            $conditions[] = "COALESCE(a.retry, CAST(a.data->>'index' AS integer)) = ?";
            $parameters[] = $retry;
            $matches = $this->connection->fetchAllAssociative('SELECT a.operation_id, a.case_key, a.attempt_key FROM report_attempt a JOIN report_case c ON c.operation_id = a.operation_id AND c.case_key = a.case_key JOIN report_import i ON i.id = a.operation_id WHERE '
                .implode(' AND ', $conditions).' ORDER BY a.operation_id, a.id LIMIT 50', $parameters);
        } else {
            $file = self::scalarText($target['file'] ?? null);
            $title = self::scalarText($target['title'] ?? null);
            if (null === $file || null === $title) { return $none('unmatched', 'target_incomplete'); }
            $conditions[] = "c.data->>'file' = ?";
            $parameters[] = $file;
            $line = $target['line'] ?? null;
            if (is_int($line)) { $conditions[] = "c.data->>'line' = ?"; $parameters[] = (string) $line; }
            else { $conditions[] = "c.data->>'line' IS NULL"; }
            $candidates = $this->connection->fetchAllAssociative('SELECT c.operation_id, c.case_key, c.data FROM report_case c JOIN report_import i ON i.id = c.operation_id WHERE '
                .implode(' AND ', $conditions).' ORDER BY c.operation_id, c.id LIMIT 200', $parameters);
            $matches = [];
            foreach ($candidates as $candidate) {
                $case = self::decode($candidate['data']);
                $path = array_values(array_filter(is_array($case['title_path'] ?? null) ? $case['title_path'] : [], 'is_string'));
                $name = is_string($case['name'] ?? null) ? $case['name'] : null;
                // UXor durations name a test by its describe path and title joined with Playwright's separator.
                $full = is_string($case['full_title'] ?? null) ? $case['full_title'] : (null === $name ? null : implode(' › ', [...$path, $name]));
                if (null !== $full && $title === $full) {
                    $matches[] = ['operation_id' => $candidate['operation_id'], 'case_key' => $candidate['case_key'], 'attempt_key' => null];
                }
            }
        }
        if ([] === $matches) { return $none('unmatched', $attemptLevel ? 'attempt_not_found' : 'case_not_found'); }
        if (count(array_unique(array_column($matches, 'operation_id'))) > 1) { return $none('ambiguous', 'multiple_native_imports'); }
        if (count($matches) > 1) { return $none('ambiguous', $attemptLevel ? 'multiple_attempts' : 'multiple_cases'); }

        return ['status' => 'linked', 'reason' => null] + $matches[0];
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
        // The expiration commit is the authorization to delete. Never unlink before it succeeds.
        $result = $this->database(fn () => $this->connection->transactional(function (): array {
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
            }
            $pruned = $this->connection->fetchAllAssociative("SELECT id, project_id, execution_id, format FROM report_import WHERE status = 'completed' AND completed_at < ? ORDER BY completed_at, id LIMIT 100 FOR UPDATE", [$recordCutoff]);
            $relink = [];
            foreach ($pruned as $import) {
                // A derived annotation never silently points at pruned native evidence.
                $this->connection->executeStatement("UPDATE report_annotation SET match_status = 'unmatched', match_reason = 'native_report_pruned', target_operation_id = NULL, target_case_key = NULL, target_attempt_key = NULL, linked_at = NULL WHERE target_operation_id = ?", [$import['id']]);
                $this->connection->delete('report_import', ['id' => $import['id']]);
                if (self::NATIVE_FORMAT === $import['format']) { $relink[$import['execution_id']] = $import['project_id']; }
            }
            // Remaining native imports may now resolve annotations that were ambiguous or pointed at the pruned import.
            foreach ($relink as $executionId => $projectId) {
                $this->linkLock((string) $executionId);
                $this->linkAnnotations($projectId, (string) $executionId, null, 'native_report_pruned');
            }
            return ['schema_version' => 1, 'expired' => count($eligible), 'pruned' => count($pruned),
                'orphan_blobs' => 0, 'staged_files' => 0, 'deferred' => null];
        }));
        if (null !== $result['deferred']) { return $result; }

        // This transaction only provides serialization for deletion; expiration is already durable.
        // Old expired rows are retried on every sweep, independently of new expirations.
        $files = $this->database(fn () => $this->connection->transactional(function (): array {
            $this->limits->lock();
            if (0 !== (int) $this->connection->fetchOne("SELECT COUNT(*) FROM messenger_messages WHERE queue_name IN ('async', 'failed')")) {
                return ['orphan_blobs' => 0, 'staged_files' => 0, 'deferred' => 'queue_not_idle'];
            }
            $failed = false;
            $expired = $this->connection->fetchFirstColumn('SELECT DISTINCT a.sha256 FROM report_artifact a WHERE a.expired_at IS NOT NULL AND NOT EXISTS (SELECT 1 FROM report_artifact active WHERE active.sha256 = a.sha256 AND active.expired_at IS NULL) ORDER BY a.sha256 LIMIT 100');
            foreach ($expired as $hash) {
                try { $this->store->remove($hash); }
                catch (\Throwable) { $failed = true; }
            }
            $hashes = $this->connection->fetchFirstColumn('SELECT DISTINCT sha256 FROM report_artifact WHERE expired_at IS NULL');
            try { $orphans = $this->store->cleanupOrphans($hashes, time() - 3600); }
            catch (\Throwable) { $failed = true; $orphans = ['orphan_blobs' => 0, 'staged_files' => 0]; }

            return array_merge($orphans, ['deferred' => $failed ? 'storage_cleanup_unavailable' : null]);
        }));

        // Catalogue retention does not depend on a successful filesystem deletion. Any leftover
        // unreferenced blob remains accounted for and is retried by the bounded orphan sweep.
        $this->database(fn () => $this->connection->transactional(function (): void {
            $this->limits->lock();
            $unused = $this->connection->fetchFirstColumn('SELECT a.id FROM report_artifact a WHERE a.expired_at IS NOT NULL AND NOT EXISTS (SELECT 1 FROM report_import i WHERE i.artifact_id = a.id) ORDER BY a.id LIMIT 100');
            foreach ($unused as $id) { $this->connection->delete('report_artifact', ['id' => $id]); }
        }));

        return array_merge($result, $files);
    }

    /** @param resource $stream */
    private function fingerprint($stream): array
    {
        if (!is_resource($stream) || 'stream' !== get_resource_type($stream)) {
            throw new \InvalidArgumentException('Report content must be a readable stream.');
        }
        $digest = hash_init('sha256');
        $bytes = 0;
        while (!feof($stream)) {
            $block = fread($stream, 65536);
            if (false === $block || ('' === $block && !feof($stream))) { throw new \RuntimeException('Report content could not be read.'); }
            $bytes += strlen($block);
            if ($bytes > $this->limits->uploadMaximum()) { throw new \InvalidArgumentException('Report content exceeds the configured upload byte limit.'); }
            hash_update($digest, $block);
        }

        return ['sha256' => hash_final($digest), 'bytes' => $bytes];
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

    private function artifact(array $row, bool $verify = true): array
    {
        return ['schema_version' => 1, 'artifact_id' => $row['id'], 'project_id' => $row['project_id'], 'execution_id' => $row['execution_id'],
            'filename' => $row['filename'], 'media_type' => $row['media_type'], 'sha256' => $row['sha256'], 'bytes' => (int) $row['bytes'],
            'created_at' => self::iso($row['created_at']), 'expired_at' => null === $row['expired_at'] ? null : self::iso($row['expired_at']),
            'availability' => null === $row['expired_at'] ? $this->store->availability($row['sha256'], (int) $row['bytes'], $verify) : ['status' => 'expired', 'available' => false, 'integrity_checked' => false]];
    }

    private function operation(array $row, bool $verify = true): array
    {
        return ['schema_version' => 1, 'operation_id' => $row['id'], 'correlation_id' => $row['id'], 'project_id' => $row['project_id'], 'execution_id' => $row['execution_id'],
            'request_id' => $row['request_id'], 'report_role' => $row['report_role'], 'format' => $row['format'], 'status' => $row['status'],
            'parser_outcome' => $row['parser_outcome'], 'valid_document' => null === $row['valid_document'] ? null : in_array($row['valid_document'], [true, 1, '1', 't', 'true'], true),
            'artifact' => $this->artifact($this->artifactRow($row['project_id'], $row['execution_id'], $row['artifact_id']), $verify),
            'parser' => ['name' => $row['parser_name'], 'version' => $row['parser_version']], 'source_context' => self::decode($row['source_context']),
            'created_at' => self::iso($row['created_at']), 'started_at' => null === $row['started_at'] ? null : self::iso($row['started_at']),
            'completed_at' => null === $row['completed_at'] ? null : self::iso($row['completed_at']),
            'summary' => $summary = (null === $row['summary'] ? ['cases' => null, 'suites' => null, 'observed_counts' => null, 'declared_counts' => null] : self::decode($row['summary'])),
            'limitations' => self::decode($row['limitations']), 'error' => null === $row['error_code'] ? null : ['code' => $row['error_code'], 'message' => $row['error_message']],
            'timings' => self::timings($row),
            'attempt_count' => is_int($summary['attempts'] ?? null) ? $summary['attempts'] : null,
            'annotation_count' => is_int($summary['annotations'] ?? null) ? $summary['annotations'] : null,
            'links' => is_int($summary['annotations'] ?? null) && $summary['annotations'] > 0 ? $this->linkCounts($row['id']) : null];
    }

    /** Stage durations from stored timestamps; null marks a stage or measurement that has not happened. */
    private static function timings(array $row): array
    {
        $seconds = static fn (?string $from, ?string $to): ?float => null === $from || null === $to ? null
            : round((float) (new \DateTimeImmutable($to))->format('U.u') - (float) (new \DateTimeImmutable($from))->format('U.u'), 3);

        return ['queued_seconds' => $seconds($row['created_at'], $row['started_at']),
            'processing_seconds' => 'completed' === $row['status'] ? $seconds($row['started_at'], $row['completed_at']) : null,
            'parse_ms' => null === ($row['processing_ms'] ?? null) ? null : (int) $row['processing_ms']];
    }

    private function linkCounts(string $operationId): array
    {
        $counts = ['linked' => 0, 'unmatched' => 0, 'ambiguous' => 0, 'not_applicable' => 0];
        foreach ($this->database(fn () => $this->connection->fetchAllAssociative('SELECT match_status, COUNT(*) AS total FROM report_annotation WHERE operation_id = ? GROUP BY match_status', [$operationId])) as $row) {
            $counts[$row['match_status']] = (int) $row['total'];
        }

        return $counts;
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

    private static function scalarText(mixed $value): ?string
    {
        return is_string($value) && '' !== $value ? $value : (is_int($value) ? (string) $value : null);
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
