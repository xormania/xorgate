<?php

declare(strict_types=1);

namespace App\Report;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

/** Safe, permanent parser diagnostics. Do not expose libxml messages or local paths. */
#[Exclude]
final class ReportParseException extends \RuntimeException
{
    public function __construct(public readonly string $outcome, public readonly string $errorCode)
    {
        parent::__construct('report_xml_end_ambiguous' === $errorCode ? 'The XML report could not be classified as malformed or truncated.' : match ($outcome) {
            'missing' => 'The retained report is unavailable.',
            'empty' => 'The report contains no XML document.',
            'truncated' => 'The XML report ends before its document is complete.',
            'unsupported' => 'The XML report uses an unsupported structure or declaration.',
            'oversized' => 'The report exceeds a parser limit.',
            default => 'The report is not a well-formed XML document.',
        });
    }
}
