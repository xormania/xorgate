<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Service\ReportIntake;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Messenger\Event\WorkerRunningEvent;

final class ReportRetentionListener
{
    private float $nextSweep = 0.0;

    public function __construct(private readonly ReportIntake $intake, private readonly LoggerInterface $logger) { }

    #[AsEventListener]
    public function __invoke(WorkerRunningEvent $event): void
    {
        $now = hrtime(true) / 1_000_000_000;
        if (!$event->isWorkerIdle() || $now < $this->nextSweep) { return; }
        $this->nextSweep = $now + 60;
        try { $this->intake->cleanup(); }
        catch (\Throwable) { $this->logger->warning('report.retention_unavailable'); }
    }
}
