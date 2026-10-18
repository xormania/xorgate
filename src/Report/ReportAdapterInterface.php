<?php

declare(strict_types=1);

namespace App\Report;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/** A report adapter reads retained bytes; storage and durable operation state belong to intake. */
#[AutoconfigureTag('app.report_adapter')]
interface ReportAdapterInterface
{
    public function format(): string;

    public function version(): string;

    public function parse(string $path, ?ReportParseLimits $limits = null): ParsedReport;

    /** @param resource $stream Caller-owned, verified retained bytes, positioned at the start. */
    public function parseStream(mixed $stream, ?ReportParseLimits $limits = null): ParsedReport;
}
