<?php

declare(strict_types=1);

namespace App\Service;

use App\Exception\RegistryCapacityException;
use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/** Admission is installation-wide: the current HTTP token is not a caller identity. */
final class RegistryLimits
{
    public const LOCK_KEY = 1886744691;

    public function __construct(
        private readonly Connection $connection,
        #[Autowire('%env(int:XORGATE_REGISTRY_MAX_PROJECTS)%')]
        private readonly int $maxProjects,
        #[Autowire('%env(int:XORGATE_REGISTRY_MAX_EXECUTIONS)%')]
        private readonly int $maxExecutions,
        #[Autowire('%env(int:XORGATE_REGISTRY_MAX_REQUESTS)%')]
        private readonly int $maxRequests,
        #[Autowire('%env(int:XORGATE_REGISTRY_MAX_PER_MINUTE)%')]
        private readonly int $maxPerMinute,
    ) {
        if (min($maxProjects, $maxExecutions, $maxRequests, $maxPerMinute) < 1) {
            throw new \InvalidArgumentException('Project registry limits must be positive integers.');
        }
    }

    public function lock(): void
    {
        if (!$this->connection->isTransactionActive()) {
            throw new \LogicException('Registry admission requires a transaction.');
        }

        $this->connection->executeStatement("SET LOCAL lock_timeout = '2s'");
        $this->connection->executeQuery('SELECT pg_advisory_xact_lock(?)', [self::LOCK_KEY]);
    }

    /** Replays call neither admission nor insertion and therefore remain usable at capacity. */
    public function assertCapacity(string $action): void
    {
        $capacity = $this->snapshot($action);
        if ('limited' === $capacity['status']) {
            throw new RegistryCapacityException($capacity);
        }
    }

    public function snapshot(?string $action = null): array
    {
        $row = $this->connection->fetchAssociative("SELECT (SELECT COUNT(*) FROM managed_project) AS projects, (SELECT COUNT(*) FROM execution_record) AS executions, COUNT(*) AS requests, COUNT(*) FILTER (WHERE created_at >= CURRENT_TIMESTAMP - INTERVAL '1 minute') AS recent FROM registry_request");
        $usage = [
            'projects' => (int) $row['projects'],
            'executions' => (int) $row['executions'],
            'requests' => (int) $row['requests'],
            'writes_last_minute' => (int) $row['recent'],
        ];
        $reason = match (true) {
            $usage['requests'] >= $this->maxRequests => 'registry_request_limit',
            $usage['writes_last_minute'] >= $this->maxPerMinute => 'registry_rate_limit',
            ('register' === $action || null === $action) && $usage['projects'] >= $this->maxProjects => 'project_limit',
            ('record_execution' === $action || null === $action) && $usage['executions'] >= $this->maxExecutions => 'execution_limit',
            default => null,
        };

        return [
            'status' => null === $reason ? 'available' : 'limited',
            'reason' => $reason,
            'retry_after_seconds' => 'registry_rate_limit' === $reason ? 60 : null,
            'usage' => $usage,
            'limits' => [
                'projects' => $this->maxProjects,
                'executions' => $this->maxExecutions,
                'requests' => $this->maxRequests,
                'writes_per_minute' => $this->maxPerMinute,
            ],
        ];
    }
}
