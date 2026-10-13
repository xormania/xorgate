<?php

declare(strict_types=1);

namespace App\Service;

use App\Message\RunFoundationProbe;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Uid\Uuid;

/** Operational bootstrap proof; deliberately independent of the future verification model. */
final class FoundationProbe
{
    public function __construct(
        private readonly Connection $connection,
        private readonly MessageBusInterface $bus,
        private readonly ArtifactStorage $artifacts,
        private readonly LoggerInterface $logger,
    ) {
    }

    public static function canonicalId(string $probeId): string
    {
        if (!Uuid::isValid($probeId)) {
            throw new \InvalidArgumentException('The probe ID must be a UUID.');
        }

        return Uuid::fromString($probeId)->toRfc4122();
    }

    /** A supplied UUID makes dispatch idempotent, including concurrent requests. */
    public function dispatch(?string $probeId = null): array
    {
        $probeId = null === $probeId ? Uuid::v7()->toRfc4122() : self::canonicalId($probeId);

        try {
            $created = $this->connection->transactional(function () use ($probeId): bool {
                $inserted = $this->connection->executeStatement(
                    'INSERT INTO foundation_probe (id, status, created_at) VALUES (?, ?, ?) ON CONFLICT (id) DO NOTHING',
                    [$probeId, 'queued', self::now()],
                );

                if (0 === $inserted) {
                    return false;
                }

                // Doctrine transport uses the same DBAL connection: row and queued message commit together.
                $this->bus->dispatch(new RunFoundationProbe($probeId));

                return true;
            });
        } catch (\Throwable $exception) {
            $this->logger->error('foundation.probe.dispatch_failed', [
                'probe_id' => $probeId, 'correlation_id' => $probeId, 'exception_type' => $exception::class,
            ]);
            throw new \RuntimeException('Foundation probe could not be queued. Verify database and queue readiness.');
        }

        $this->logger->info($created ? 'foundation.probe.queued' : 'foundation.probe.existing', [
            'probe_id' => $probeId, 'correlation_id' => $probeId,
        ]);

        return $this->get($probeId);
    }

    public function get(string $probeId): array
    {
        $probeId = self::canonicalId($probeId);

        try {
            $row = $this->connection->fetchAssociative('SELECT * FROM foundation_probe WHERE id = ?', [$probeId]);
        } catch (\Throwable) {
            throw new \RuntimeException('Foundation probe data is unavailable.');
        }

        if (false === $row) {
            throw new \InvalidArgumentException('The foundation probe was not found.');
        }

        $artifact = null;
        if (null !== $row['artifact_path']) {
            $artifact = [
                'path' => $row['artifact_path'],
                'sha256' => $row['artifact_sha256'],
                'bytes' => (int) $row['artifact_bytes'],
                'available' => $this->artifacts->isAvailable($row['artifact_path'], $row['artifact_sha256']),
            ];
        }

        return [
            'probe_id' => $probeId,
            'correlation_id' => $probeId,
            'status' => $row['status'],
            'created_at' => self::iso($row['created_at']),
            'completed_at' => null === $row['completed_at'] ? null : self::iso($row['completed_at']),
            'error' => $row['error_code'],
            'artifact' => $artifact,
        ];
    }

    public function complete(string $probeId): void
    {
        $probeId = self::canonicalId($probeId);

        try {
            $completed = $this->connection->transactional(function () use ($probeId): bool {
                // Serialize concurrent/redelivered attempts before writing or recording the artifact.
                $row = $this->connection->fetchAssociative('SELECT * FROM foundation_probe WHERE id = ? FOR UPDATE', [$probeId]);
                if (false === $row) {
                    throw new \RuntimeException('The foundation probe was not found.');
                }
                if ('completed' === $row['status']) {
                    return false;
                }

                $completedAt = self::now();
                $artifact = $this->artifacts->writeProbe($probeId, [
                    'kind' => 'xorgate_foundation_probe',
                    'probe_id' => $probeId,
                    'correlation_id' => $probeId,
                    'queued_at' => self::iso($row['created_at']),
                    'completed_at' => $completedAt,
                    'checks' => ['database' => 'ready', 'queue' => 'consumed', 'artifacts' => 'written'],
                ]);

                $this->connection->executeStatement(
                    'UPDATE foundation_probe SET status = ?, completed_at = ?, artifact_path = ?, artifact_sha256 = ?, artifact_bytes = ?, error_code = NULL WHERE id = ?',
                    ['completed', $completedAt, $artifact['path'], $artifact['sha256'], $artifact['bytes'], $probeId],
                );

                return true;
            });
        } catch (\Throwable $exception) {
            // A failed attempt remains inspectable while Messenger schedules a retry or failure transport.
            try {
                $this->connection->executeStatement(
                    'UPDATE foundation_probe SET status = ?, error_code = ? WHERE id = ? AND status <> ?',
                    ['failed', 'probe_execution_failed', $probeId, 'completed'],
                );
            } catch (\Throwable) {
                // Database loss must not suppress the transport's retry/failure handling.
            }

            $this->logger->error('foundation.probe.execution_failed', [
                'probe_id' => $probeId, 'correlation_id' => $probeId, 'exception_type' => $exception::class,
            ]);
            throw new \RuntimeException('Foundation probe execution failed. Inspect runtime readiness and the failed transport.');
        }

        $this->logger->info($completed ? 'foundation.probe.completed' : 'foundation.probe.redelivery_skipped', [
            'probe_id' => $probeId, 'correlation_id' => $probeId,
        ]);
    }

    private static function now(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(\DateTimeInterface::ATOM);
    }

    private static function iso(string $timestamp): string
    {
        return (new \DateTimeImmutable($timestamp, new \DateTimeZone('UTC')))
            ->setTimezone(new \DateTimeZone('UTC'))->format(\DateTimeInterface::ATOM);
    }
}
