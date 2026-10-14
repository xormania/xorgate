<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\NormalizeReport;
use App\Service\ReportIntake;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class NormalizeReportHandler
{
    public function __construct(private readonly ReportIntake $intake) { }
    public function __invoke(NormalizeReport $message): void { $this->intake->process($message->operationId); }
}
