<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Service\FoundationProbeLimits;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Messenger\Event\WorkerRunningEvent;

final class FoundationProbeRetentionListener
{
    private float $nextSweep = 0.0;

    public function __construct(private readonly FoundationProbeLimits $limits, private readonly LoggerInterface $logger)
    {
    }

    #[AsEventListener]
    public function __invoke(WorkerRunningEvent $event): void
    {
        $now = hrtime(true) / 1_000_000_000;
        if (!$event->isWorkerIdle() || $now < $this->nextSweep) {
            return;
        }
        $this->nextSweep = $now + 60;

        try {
            $this->limits->cleanup();
        } catch (\Throwable) {
            // Retry on a later idle tick. Never kill the worker or disclose internal exceptions.
            $this->logger->warning('foundation.probe.retention_unavailable');
        }
    }
}
