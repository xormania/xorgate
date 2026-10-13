<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\RunFoundationProbe;
use App\Service\FoundationProbe;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class RunFoundationProbeHandler
{
    public function __construct(private readonly FoundationProbe $probes)
    {
    }

    public function __invoke(RunFoundationProbe $message): void
    {
        $this->probes->complete($message->probeId);
    }
}
