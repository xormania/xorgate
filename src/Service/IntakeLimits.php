<?php

declare(strict_types=1);

namespace App\Service;

use App\Exception\IntakeCapacityException;
use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/** Shared installation budget; the current bearer token does not identify separate actors. */
final class IntakeLimits
{
    public const LOCK_KEY = 1886744692;

    public function __construct(
        private readonly Connection $connection,
        private readonly ReportArtifactStore $store,
        #[Autowire('%env(int:XORGATE_INTAKE_MAX_PENDING)%')] private readonly int $maxPending,
        #[Autowire('%env(int:XORGATE_INTAKE_MAX_RETAINED)%')] private readonly int $maxRetained,
        #[Autowire('%env(int:XORGATE_INTAKE_MAX_PER_MINUTE)%')] private readonly int $maxPerMinute,
        #[Autowire('%env(int:XORGATE_INTAKE_MAX_UPLOAD_BYTES)%')] private readonly int $maxUploadBytes,
        #[Autowire('%env(int:XORGATE_INTAKE_MAX_STORAGE_BYTES)%')] private readonly int $maxStorageBytes,
        #[Autowire('%env(int:XORGATE_INTAKE_ARTIFACT_RETENTION_SECONDS)%')] private readonly int $artifactRetentionSeconds,
        #[Autowire('%env(int:XORGATE_INTAKE_RECORD_RETENTION_SECONDS)%')] private readonly int $recordRetentionSeconds,
    ) {
        if (min($maxPending, $maxRetained, $maxPerMinute, $maxUploadBytes, $maxStorageBytes) < 1
            || $maxRetained < $maxPending || $maxStorageBytes < $maxUploadBytes
            || $artifactRetentionSeconds < 60 || $recordRetentionSeconds < $artifactRetentionSeconds) {
            throw new \InvalidArgumentException('Intake limits must be positive, retained capacity must cover pending work, storage must cover one upload, and record retention must cover artifact retention of at least 60 seconds.');
        }
    }

    public function lock(): void
    {
        if (!$this->connection->isTransactionActive()) {
            throw new \LogicException('Intake admission requires a transaction.');
        }
        $this->connection->executeStatement("SET LOCAL lock_timeout = '2s'");
        $this->connection->executeQuery('SELECT pg_advisory_xact_lock(?)', [self::LOCK_KEY]);
    }

    public function uploadMaximum(): int { return $this->maxUploadBytes; }
    public function artifactRetention(): int { return $this->artifactRetentionSeconds; }
    public function recordRetention(): int { return $this->recordRetentionSeconds; }

    public function assertCapacity(int $newReferenceBytes, string $hash, bool $newArtifact): void
    {
        $capacity = $this->snapshot();
        $physical = $this->store->availability($hash, $newReferenceBytes);
        $newBlobBytes = 'missing' === $physical['status'] ? $newReferenceBytes : 0;
        $reason = $capacity['reason'];
        if (null === $reason && ($capacity['usage']['reference_bytes'] + ($newArtifact ? $newReferenceBytes : 0) > $this->maxStorageBytes
            || $capacity['usage']['blob_bytes'] + $newBlobBytes > $this->maxStorageBytes)) {
            $reason = 'intake_storage_limit';
        }
        if (null === $reason && $newBlobBytes > 0 && $capacity['usage']['blob_files'] >= $this->maxRetained) {
            $reason = 'intake_blob_limit';
        }
        if (null !== $reason) {
            $capacity['status'] = 'limited';
            $capacity['reason'] = $reason;
            throw new IntakeCapacityException($capacity);
        }
    }

    public function snapshot(): array
    {
        $row = $this->connection->fetchAssociative("SELECT COUNT(*) AS retained, COUNT(*) FILTER (WHERE status <> 'completed') AS pending, COUNT(*) FILTER (WHERE created_at >= CURRENT_TIMESTAMP - INTERVAL '1 minute') AS recent FROM report_import");
        $referenceBytes = (int) $this->connection->fetchOne('SELECT COALESCE(SUM(bytes), 0) FROM report_artifact WHERE expired_at IS NULL');
        $physical = $this->store->usage($this->maxRetained);
        $usage = ['pending' => (int) $row['pending'], 'retained' => (int) $row['retained'],
            'created_last_minute' => (int) $row['recent'], 'reference_bytes' => $referenceBytes,
            'blob_bytes' => $physical['bytes'], 'blob_files' => $physical['files'], 'blob_scan_truncated' => $physical['scan_truncated']];
        $reason = match (true) {
            $usage['pending'] >= $this->maxPending => 'intake_pending_limit',
            $usage['retained'] >= $this->maxRetained => 'intake_retained_limit',
            $usage['created_last_minute'] >= $this->maxPerMinute => 'intake_rate_limit',
            $referenceBytes >= $this->maxStorageBytes || $physical['bytes'] >= $this->maxStorageBytes => 'intake_storage_limit',
            $physical['scan_truncated'] => 'intake_blob_limit',
            default => null,
        };

        return ['schema_version' => 1, 'status' => null === $reason ? 'available' : 'limited', 'reason' => $reason,
            'actor_scope' => 'shared_installation', 'retry_after_seconds' => 'intake_rate_limit' === $reason ? 60 : null,
            'usage' => $usage, 'limits' => ['pending' => $this->maxPending, 'retained' => $this->maxRetained,
                'new_per_minute' => $this->maxPerMinute, 'upload_bytes' => $this->maxUploadBytes,
                'storage_bytes' => $this->maxStorageBytes, 'blob_files' => $this->maxRetained,
                'inline_bytes' => 65536, 'read_bytes' => 32768, 'cases_per_report' => 10000,
                'artifact_retention_seconds' => $this->artifactRetentionSeconds, 'record_retention_seconds' => $this->recordRetentionSeconds]];
    }
}
