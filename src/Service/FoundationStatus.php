<?php

declare(strict_types=1);

namespace App\Service;

use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpKernel\Kernel;

final class FoundationStatus
{
    public function __construct(
        private readonly Connection $connection,
        private readonly ArtifactStorage $artifacts,
        private readonly FoundationProbeLimits $limits,
        #[Autowire('%kernel.environment%')]
        private readonly string $environment,
    ) {
    }

    public function snapshot(): array
    {
        $checks = [];
        $capacity = ['status' => 'unavailable'];

        try {
            $this->connection->fetchOne('SELECT COUNT(*) FROM foundation_probe');
            $capacity = $this->limits->snapshot();
            $checks['database'] = ['status' => 'ready', 'detail' => 'Database and foundation schema are reachable.'];
        } catch (\Throwable) {
            $checks['database'] = ['status' => 'unavailable', 'detail' => 'Database or foundation schema is unavailable.'];
        }

        try {
            $this->artifacts->checkWritable();
            $checks['artifacts'] = ['status' => 'ready', 'detail' => 'Persistent artifact storage accepts atomic writes.'];
        } catch (\Throwable) {
            $checks['artifacts'] = ['status' => 'unavailable', 'detail' => 'Artifact storage is unavailable.'];
        }

        try {
            $this->connection->fetchOne('SELECT COUNT(*) FROM messenger_messages WHERE queue_name IN (?, ?)', ['async', 'failed']);
            $checks['queue'] = ['status' => 'ready', 'detail' => 'Doctrine queue storage is reachable. A completed probe verifies worker execution.'];
        } catch (\Throwable) {
            $checks['queue'] = ['status' => 'unavailable', 'detail' => 'Doctrine queue storage is unavailable.'];
        }

        return [
            'application' => ['name' => 'xorgate', 'framework' => Kernel::VERSION, 'environment' => $this->environment],
            'checks' => $checks,
            'probe_capacity' => $capacity,
            'ready' => !\in_array('unavailable', array_column($checks, 'status'), true),
            'observed_at' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(\DateTimeInterface::ATOM),
        ];
    }
}
