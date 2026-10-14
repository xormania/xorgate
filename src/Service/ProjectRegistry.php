<?php

declare(strict_types=1);

namespace App\Service;

use App\Exception\RegistryConflictException;
use App\Exception\RegistryNotFoundException;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DatabaseException;
use Symfony\Component\Uid\Uuid;

/** The web, MCP and CLI share these bounded, synchronous metadata operations. */
final class ProjectRegistry
{
    private const SOURCE_FIELDS = [
        'repository_identity' => 160, 'repository_url' => 2048, 'tested_revision' => 256,
        'workspace_fingerprint' => 256, 'pr_head_revision' => 256,
        'pr_base_revision' => 256, 'pr_merge_revision' => 256,
    ];

    public function __construct(private readonly Connection $connection, private readonly RegistryLimits $limits)
    {
    }

    public static function canonicalId(string $id): string
    {
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/iD', $id) || !Uuid::isValid($id)) {
            throw new \InvalidArgumentException('The ID must be a UUID.');
        }

        return Uuid::fromString($id)->toRfc4122();
    }

    public function register(array $input): array
    {
        $payload = self::projectInput($input, false);

        return $this->mutate('register', null, $payload, function () use ($payload): array {
            $id = Uuid::v7()->toRfc4122();
            $now = self::now();
            $this->connection->insert('managed_project', [
                'id' => $id, 'name' => $payload['name'],
                'repository_url' => $payload['repository_url'],
                'repository_identity' => $payload['repository_identity'],
                'runtimes' => self::json($payload['runtimes']),
                'version' => 1, 'created_at' => $now, 'updated_at' => $now,
            ]);

            return $this->get($id);
        });
    }

    /** Optimistic editing preserves resource identity and historical execution snapshots. */
    public function update(string $projectId, array $input): array
    {
        $projectId = self::canonicalId($projectId);
        $payload = self::projectInput($input, true);

        return $this->mutate('update', $projectId, $payload, function () use ($projectId, $payload): array {
            $project = $this->get($projectId);
            if ($project['version'] !== $payload['expected_version']) {
                throw new RegistryConflictException('The project has changed. Read its current version before editing.');
            }

            $changed = $this->connection->executeStatement(
                'UPDATE managed_project SET name = ?, repository_url = ?, repository_identity = ?, runtimes = ?, version = version + 1, updated_at = ? WHERE id = ? AND version = ?',
                [$payload['name'], $payload['repository_url'], $payload['repository_identity'], self::json($payload['runtimes']), self::now(), $projectId, $payload['expected_version']],
            );
            if (1 !== $changed) {
                throw new RegistryConflictException('The project has changed. Read its current version before editing.');
            }

            return $this->get($projectId);
        });
    }

    public function get(string $projectId): array
    {
        $projectId = self::canonicalId($projectId);
        $row = $this->read(fn () => $this->connection->fetchAssociative('SELECT * FROM managed_project WHERE id = ?', [$projectId]));
        if (false === $row) {
            throw new RegistryNotFoundException('The project was not found.');
        }

        return self::project($row);
    }

    public function list(int $limit = 25, ?string $cursor = null): array
    {
        self::limit($limit);
        $cursor = null === $cursor ? null : self::canonicalId($cursor);
        if (null !== $cursor) {
            $this->get($cursor);
        }
        $rows = $this->read(fn () => $this->connection->fetchAllAssociative(
            'SELECT * FROM managed_project'.(null === $cursor ? '' : ' WHERE id > ?').' ORDER BY id LIMIT '.($limit + 1),
            null === $cursor ? [] : [$cursor],
        ));

        return self::page($rows, $limit, self::project(...), 'project_id');
    }

    public function recordExecution(string $projectId, array $input): array
    {
        $projectId = self::canonicalId($projectId);
        $payload = self::executionInput($input);

        return $this->mutate('record_execution', $projectId, $payload, function () use ($projectId, $payload): array {
            $project = $this->get($projectId);
            $source = $payload['source'];
            // Omission asks for a project snapshot; explicit null preserves genuinely unknown metadata.
            foreach (['repository_identity', 'repository_url'] as $field) {
                if (!array_key_exists($field, $source)) {
                    $source[$field] = $project[$field];
                }
            }
            if (null !== $project['repository_identity'] && null !== $source['repository_identity']
                && $project['repository_identity'] !== $source['repository_identity']) {
                throw new RegistryConflictException('Execution repository identity does not match the project.');
            }
            // Produce a stable field order irrespective of which repository defaults were omitted.
            $source = array_replace(array_fill_keys(array_keys(self::SOURCE_FIELDS), null), $source);
            $id = Uuid::v7()->toRfc4122();
            $this->connection->insert('execution_record', [
                'id' => $id, 'project_id' => $projectId,
                'source' => self::json($source), 'environment' => self::json($payload['environment']),
                'producer' => self::json($payload['producer']), 'native_identity' => self::json($payload['native_identity']),
                'observed_at' => $payload['observed_at'], 'created_at' => self::now(),
            ]);

            return $this->getExecution($projectId, $id);
        });
    }

    public function getExecution(string $projectId, string $executionId): array
    {
        $projectId = self::canonicalId($projectId);
        $executionId = self::canonicalId($executionId);
        $this->get($projectId);
        $row = $this->read(fn () => $this->connection->fetchAssociative('SELECT * FROM execution_record WHERE project_id = ? AND id = ?', [$projectId, $executionId]));
        if (false === $row) {
            throw new RegistryNotFoundException('The execution was not found in this project.');
        }

        return self::execution($row);
    }

    public function listExecutions(string $projectId, int $limit = 25, ?string $cursor = null): array
    {
        $projectId = self::canonicalId($projectId);
        self::limit($limit);
        $cursor = null === $cursor ? null : self::canonicalId($cursor);
        $this->get($projectId);
        if (null !== $cursor) {
            $this->getExecution($projectId, $cursor);
        }
        $rows = $this->read(fn () => $this->connection->fetchAllAssociative(
            'SELECT * FROM execution_record WHERE project_id = ?'.(null === $cursor ? '' : ' AND id > ?').' ORDER BY id LIMIT '.($limit + 1),
            null === $cursor ? [$projectId] : [$projectId, $cursor],
        ));

        return self::page($rows, $limit, self::execution(...), 'execution_id');
    }

    /** Ingestion must supply the project and its known source context before attaching evidence. */
    public function assertExecutionContext(string $projectId, string $executionId, array $expectedSource): array
    {
        self::fields($expectedSource, array_keys(self::SOURCE_FIELDS), 'source');
        $expected = [];
        foreach ($expectedSource as $field => $value) {
            $expected[$field] = self::text($value, self::SOURCE_FIELDS[$field], 'source.'.$field);
            if ('repository_url' === $field) {
                self::repositoryUrl($expected[$field]);
            }
        }
        if ([] === array_filter($expected, static fn (?string $value): bool => null !== $value)) {
            throw new \InvalidArgumentException('Supply at least one known source field to verify execution context.');
        }
        $execution = $this->getExecution($projectId, $executionId);
        foreach ($expected as $field => $value) {
            if ($execution['source'][$field] !== $value) {
                throw new RegistryConflictException('Execution source context does not match the supplied evidence context.');
            }
        }

        return $execution;
    }

    private function mutate(string $action, ?string $projectId, array $payload, callable $operation): array
    {
        // Hash the request before resolving defaults: metadata edits must not change retry semantics.
        $hashPayload = $payload;
        unset($hashPayload['request_id']);
        $digest = hash('sha256', self::json(['action' => $action, 'project_id' => $projectId, 'input' => $hashPayload]));

        return $this->read(fn () => $this->connection->transactional(function () use ($action, $projectId, $payload, $digest, $operation): array {
            $this->limits->lock();
            $request = $this->connection->fetchAssociative('SELECT payload_sha256, response FROM registry_request WHERE id = ?', [$payload['request_id']]);
            if (false !== $request) {
                if (!hash_equals($request['payload_sha256'], $digest)) {
                    throw new RegistryConflictException('The request ID was already used with different inputs or scope.');
                }

                return self::decode($request['response']);
            }
            $this->limits->assertCapacity($action);
            $result = $operation();
            $this->connection->insert('registry_request', [
                'id' => $payload['request_id'], 'action' => $action,
                'project_id' => $result['project_id'],
                'resource_id' => $result['execution_id'] ?? $result['project_id'],
                'payload_sha256' => $digest, 'response' => self::json($result), 'created_at' => self::now(),
            ]);

            return $result;
        }));
    }

    private function read(callable $query): mixed
    {
        try {
            return $query();
        } catch (DatabaseException) {
            throw new \RuntimeException('Project registry is unavailable. Verify database readiness.');
        }
    }

    private static function projectInput(array $input, bool $update): array
    {
        $allowed = ['schema_version', 'request_id', 'name', 'repository_url', 'repository_identity', 'runtimes'];
        if ($update) {
            $allowed[] = 'expected_version';
        }
        self::fields($input, $allowed, 'project');
        self::schema($input);
        if (!isset($input['request_id']) || !is_string($input['request_id'])) {
            throw new \InvalidArgumentException('request_id must be a UUID.');
        }
        $name = self::text($input['name'] ?? null, 120, 'name');
        if (null === $name) {
            throw new \InvalidArgumentException('name is required.');
        }
        $url = self::text($input['repository_url'] ?? null, 2048, 'repository_url');
        self::repositoryUrl($url);
        $payload = [
            'schema_version' => 1, 'request_id' => self::canonicalId($input['request_id']), 'name' => $name,
            'repository_url' => $url, 'repository_identity' => self::text($input['repository_identity'] ?? null, 160, 'repository_identity'),
            'runtimes' => self::runtimes(array_key_exists('runtimes', $input) ? $input['runtimes'] : [], 'runtimes'),
        ];
        if ($update) {
            if (!isset($input['expected_version']) || !is_int($input['expected_version']) || $input['expected_version'] < 1 || $input['expected_version'] >= 2147483647) {
                throw new \InvalidArgumentException('expected_version must be a positive integer below 2147483647.');
            }
            $payload['expected_version'] = $input['expected_version'];
        }

        return $payload;
    }

    private static function executionInput(array $input): array
    {
        self::fields($input, ['schema_version', 'request_id', 'source', 'environment', 'producer', 'native_identity', 'observed_at'], 'execution');
        self::schema($input);
        if (!isset($input['request_id']) || !is_string($input['request_id'])) {
            throw new \InvalidArgumentException('request_id must be a UUID.');
        }
        $source = self::object(array_key_exists('source', $input) ? $input['source'] : [], 'source');
        self::fields($source, array_keys(self::SOURCE_FIELDS), 'source');
        $normalizedSource = [];
        foreach (self::SOURCE_FIELDS as $field => $length) {
            // Only these two omitted fields have defaulting semantics.
            if (in_array($field, ['repository_identity', 'repository_url'], true) && !array_key_exists($field, $source)) {
                continue;
            }
            $normalizedSource[$field] = self::text($source[$field] ?? null, $length, 'source.'.$field);
        }
        self::repositoryUrl($normalizedSource['repository_url'] ?? null);
        $environment = self::object(array_key_exists('environment', $input) ? $input['environment'] : [], 'environment');
        self::fields($environment, ['name', 'runtimes', 'configuration_ref'], 'environment');
        $producer = self::object(array_key_exists('producer', $input) ? $input['producer'] : [], 'producer');
        self::fields($producer, ['name', 'version'], 'producer');
        $native = self::object(array_key_exists('native_identity', $input) ? $input['native_identity'] : [], 'native_identity');
        self::fields($native, ['provider', 'run_id', 'run_attempt', 'job_id', 'shard'], 'native_identity');
        $attempt = $native['run_attempt'] ?? null;
        if (null !== $attempt && (!is_int($attempt) || $attempt < 1 || $attempt > 2147483647)) {
            throw new \InvalidArgumentException('native_identity.run_attempt must be a positive integer or null.');
        }

        return [
            'schema_version' => 1, 'request_id' => self::canonicalId($input['request_id']),
            'source' => $normalizedSource,
            'environment' => [
                'name' => self::text($environment['name'] ?? null, 120, 'environment.name'),
                'runtimes' => self::runtimes(array_key_exists('runtimes', $environment) ? $environment['runtimes'] : [], 'environment.runtimes'),
                'configuration_ref' => self::text($environment['configuration_ref'] ?? null, 256, 'environment.configuration_ref'),
            ],
            'producer' => [
                'name' => self::text($producer['name'] ?? null, 120, 'producer.name'),
                'version' => self::text($producer['version'] ?? null, 120, 'producer.version'),
            ],
            'native_identity' => [
                'provider' => self::text($native['provider'] ?? null, 80, 'native_identity.provider'),
                'run_id' => self::text($native['run_id'] ?? null, 160, 'native_identity.run_id'),
                'run_attempt' => $attempt,
                'job_id' => self::text($native['job_id'] ?? null, 160, 'native_identity.job_id'),
                'shard' => self::text($native['shard'] ?? null, 160, 'native_identity.shard'),
            ],
            'observed_at' => self::observedAt($input['observed_at'] ?? null),
        ];
    }

    private static function fields(array $input, array $allowed, string $object): void
    {
        foreach (array_keys($input) as $key) {
            if (!is_string($key) || !in_array($key, $allowed, true)) {
                throw new \InvalidArgumentException('Unexpected field in '.$object.'.');
            }
        }
    }

    private static function object(mixed $value, string $field): array
    {
        if (!is_array($value) || ([] !== $value && array_is_list($value))) {
            throw new \InvalidArgumentException($field.' must be an object.');
        }

        return $value;
    }

    private static function schema(array $input): void
    {
        if (array_key_exists('schema_version', $input) && 1 !== $input['schema_version']) {
            throw new \InvalidArgumentException('schema_version must be 1.');
        }
    }

    private static function text(mixed $value, int $max, string $field): ?string
    {
        if (null === $value) {
            return null;
        }
        if (!is_string($value) || strlen($value) > $max * 4 || !preg_match('//u', $value)
            || preg_match('/[\x00-\x1F\x7F]/u', $value) || iconv_strlen($value, 'UTF-8') > $max) {
            throw new \InvalidArgumentException($field.' must contain at most '.$max.' characters without control characters.');
        }
        // Unicode separators and NEXT LINE are whitespace too; preserve characters within a value.
        $value = preg_replace('/\A[\s\p{Z}\x{0085}]+|[\s\p{Z}\x{0085}]+\z/u', '', $value);

        return '' === $value ? null : $value;
    }

    private static function runtimes(mixed $value, string $field): array
    {
        if (!is_array($value) || !array_is_list($value) || count($value) > 8) {
            throw new \InvalidArgumentException($field.' must be a list of at most 8 runtime names.');
        }
        $names = [];
        foreach ($value as $item) {
            $name = self::text($item, 80, $field);
            if (null === $name) {
                throw new \InvalidArgumentException($field.' must contain nonempty runtime names.');
            }
            if (!in_array($name, $names, true)) {
                $names[] = $name;
            }
        }

        return $names;
    }

    /** Repository locations are display metadata, never a network request or executable URL. */
    private static function repositoryUrl(?string $url): void
    {
        if (null === $url) {
            return;
        }
        if (preg_match('/^[a-zA-Z0-9._-]+@[a-zA-Z0-9.-]+:[^\s?#]+$/D', $url) && !str_contains($url, '://')) {
            return;
        }
        $parts = parse_url($url);
        if (false === $parts || !isset($parts['scheme'], $parts['host'])
            || !in_array(strtolower($parts['scheme']), ['https', 'http', 'ssh', 'git'], true)
            || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
            || (isset($parts['user']) && in_array(strtolower($parts['scheme']), ['https', 'http', 'git'], true))
            || preg_match('/\s/u', $url)) {
            throw new \InvalidArgumentException('repository_url must be an HTTP, HTTPS, SSH or Git repository location without embedded credentials, query or fragment.');
        }
    }

    private static function observedAt(mixed $value): ?string
    {
        if (null === $value) {
            return null;
        }
        if (!is_string($value) || strlen($value) > 64
            || !preg_match('/^\d{4}-\d{2}-\d{2}T(?:[01]\d|2[0-3]):[0-5]\d:[0-5]\d(?:\.\d{1,6})?(?:Z|[+-](?:[01]\d|2[0-3]):[0-5]\d)$/D', $value)) {
            throw new \InvalidArgumentException('observed_at must be an ISO 8601 timestamp with a timezone or null.');
        }
        try {
            $date = new \DateTimeImmutable($value);
            $errors = \DateTimeImmutable::getLastErrors();
            if (false !== $errors && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) {
                throw new \InvalidArgumentException();
            }
        } catch (\Exception) {
            throw new \InvalidArgumentException('observed_at must be a valid ISO 8601 timestamp with a timezone or null.');
        }

        return self::iso($date->format('Y-m-d\TH:i:s.uP'));
    }

    private static function limit(int $limit): void
    {
        if ($limit < 1 || $limit > 100) {
            throw new \InvalidArgumentException('limit must be between 1 and 100.');
        }
    }

    private static function page(array $rows, int $limit, callable $map, string $idKey): array
    {
        $more = count($rows) > $limit;
        $items = array_map($map, array_slice($rows, 0, $limit));

        return [
            'schema_version' => 1, 'items' => $items,
            'next_cursor' => $more ? $items[array_key_last($items)][$idKey] : null, 'limit' => $limit,
        ];
    }

    private static function project(array $row): array
    {
        return [
            'schema_version' => 1, 'project_id' => $row['id'], 'name' => $row['name'],
            'repository_url' => $row['repository_url'], 'repository_identity' => $row['repository_identity'],
            'runtimes' => self::decode($row['runtimes']), 'version' => (int) $row['version'],
            'created_at' => self::iso($row['created_at']), 'updated_at' => self::iso($row['updated_at']),
        ];
    }

    private static function execution(array $row): array
    {
        return [
            'schema_version' => 1, 'execution_id' => $row['id'], 'project_id' => $row['project_id'],
            'source' => self::decode($row['source']), 'environment' => self::decode($row['environment']),
            'producer' => self::decode($row['producer']), 'native_identity' => self::decode($row['native_identity']),
            'observed_at' => null === $row['observed_at'] ? null : self::iso($row['observed_at']),
            'created_at' => self::iso($row['created_at']),
        ];
    }

    private static function json(array $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private static function decode(string $value): array
    {
        try {
            return json_decode($value, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new \RuntimeException('Project registry data is unavailable.');
        }
    }

    private static function now(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.uP');
    }

    private static function iso(string $timestamp): string
    {
        $date = (new \DateTimeImmutable($timestamp))->setTimezone(new \DateTimeZone('UTC'));

        return $date->format('000000' === $date->format('u') ? \DateTimeInterface::ATOM : 'Y-m-d\TH:i:s.uP');
    }
}
