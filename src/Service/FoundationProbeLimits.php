<?php

declare(strict_types=1);

namespace App\Service;

use App\Exception\ProbeCapacityException;
use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/** Shared HTTP/STDIO/CLI budget: the foundation has one credential, not separate caller identities. */
final class FoundationProbeLimits
{
    // All admission and retention transactions use the same installation-wide PostgreSQL lock.
    public const LOCK_KEY = 1886744690;
    private const CLEANUP_BATCH = 100;

    public function __construct(
        private readonly Connection $connection,
        private readonly ArtifactStorage $artifacts,
        #[Autowire('%env(int:XORGATE_PROBE_MAX_PENDING)%')]
        private readonly int $maxPending,
        #[Autowire('%env(int:XORGATE_PROBE_MAX_RETAINED)%')]
        private readonly int $maxRetained,
        #[Autowire('%env(int:XORGATE_PROBE_MAX_PER_MINUTE)%')]
        private readonly int $maxPerMinute,
        #[Autowire('%env(int:XORGATE_PROBE_ARTIFACT_RETENTION_SECONDS)%')]
        private readonly int $artifactRetentionSeconds,
        #[Autowire('%env(int:XORGATE_PROBE_RECORD_RETENTION_SECONDS)%')]
        private readonly int $recordRetentionSeconds,
    ) {
        if ($maxPending < 1 || $maxRetained < $maxPending || $maxPerMinute < 1
            || $artifactRetentionSeconds < 60 || $recordRetentionSeconds < $artifactRetentionSeconds) {
            throw new \InvalidArgumentException('Probe limits must be positive, retained capacity must cover pending work, and record retention must cover artifact retention of at least 60 seconds.');
        }
    }

    public function lock(): void
    {
        if (!$this->connection->isTransactionActive()) {
            throw new \LogicException('Probe admission requires a transaction.');
        }

        $this->connection->executeStatement("SET LOCAL lock_timeout = '2s'");
        $this->connection->executeQuery('SELECT pg_advisory_xact_lock(?)', [self::LOCK_KEY]);
    }

    public function assertCapacity(): void
    {
        $capacity = $this->snapshot();
        if ('limited' === $capacity['status']) {
            throw new ProbeCapacityException($capacity);
        }
    }

    public function snapshot(): array
    {
        $row = $this->connection->fetchAssociative("SELECT COUNT(*) AS retained, COUNT(*) FILTER (WHERE status <> 'completed') AS pending, COUNT(*) FILTER (WHERE created_at >= CURRENT_TIMESTAMP - INTERVAL '1 minute') AS recent FROM foundation_probe");
        $usage = ['pending' => (int) $row['pending'], 'retained' => (int) $row['retained'], 'created_last_minute' => (int) $row['recent']];
        $reason = match (true) {
            $usage['pending'] >= $this->maxPending => 'pending_probe_limit',
            $usage['retained'] >= $this->maxRetained => 'retained_probe_limit',
            $usage['created_last_minute'] >= $this->maxPerMinute => 'probe_rate_limit',
            default => null,
        };

        return [
            'status' => null === $reason ? 'available' : 'limited',
            'reason' => $reason,
            'retry_after_seconds' => 'probe_rate_limit' === $reason ? 60 : null,
            'usage' => $usage,
            'limits' => [
                'pending' => $this->maxPending,
                'retained' => $this->maxRetained,
                'new_per_minute' => $this->maxPerMinute,
                'artifact_retention_seconds' => $this->artifactRetentionSeconds,
                'record_retention_seconds' => $this->recordRetentionSeconds,
            ],
        ];
    }

    /** Bounded cleanup, deferred while any async or failed message could still reference a probe. */
    public function cleanup(): array
    {
        return $this->connection->transactional(function (): array {
            $this->lock();
            if (0 !== (int) $this->connection->fetchOne("SELECT COUNT(*) FROM messenger_messages WHERE queue_name IN ('async', 'failed')")) {
                return ['expired' => 0, 'pruned' => 0, 'deferred' => 'queue_not_idle'];
            }

            $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
            $artifactCutoff = $now->modify('-'.$this->artifactRetentionSeconds.' seconds')->format(\DateTimeInterface::ATOM);
            $recordCutoff = $now->modify('-'.$this->recordRetentionSeconds.' seconds')->format(\DateTimeInterface::ATOM);
            $expired = $this->connection->fetchAllAssociative("SELECT id FROM foundation_probe WHERE status = 'completed' AND artifact_expired_at IS NULL AND completed_at < ? ORDER BY completed_at, id LIMIT ".self::CLEANUP_BATCH.' FOR UPDATE', [$artifactCutoff]);
            foreach ($expired as $row) {
                // Filesystem deletion is idempotent. If DB commit fails, the next sweep retries safely.
                $this->artifacts->removeProbe($row['id']);
                $this->connection->executeStatement('UPDATE foundation_probe SET artifact_expired_at = ? WHERE id = ?', [$now->format(\DateTimeInterface::ATOM), $row['id']]);
            }

            $pruned = $this->connection->fetchFirstColumn("SELECT id FROM foundation_probe WHERE status = 'completed' AND artifact_expired_at IS NOT NULL AND completed_at < ? ORDER BY completed_at, id LIMIT ".self::CLEANUP_BATCH.' FOR UPDATE', [$recordCutoff]);
            foreach ($pruned as $id) {
                $this->connection->executeStatement('DELETE FROM foundation_probe WHERE id = ?', [$id]);
            }

            return ['expired' => \count($expired), 'pruned' => \count($pruned), 'deferred' => null];
        });
    }
}
