<?php

declare(strict_types=1);

namespace App\Report;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

/** Safe, permanent parser diagnostics. Do not expose parser messages, document content or local paths. */
#[Exclude]
final class ReportParseException extends \RuntimeException
{
    /** @param 'XML'|'JSON' $syntax names the document syntax in the safe message; codes stay format-neutral. */
    public function __construct(public readonly string $outcome, public readonly string $errorCode, string $syntax = 'XML')
    {
        $syntax = 'JSON' === $syntax ? 'JSON' : 'XML';
        parent::__construct('report_xml_end_ambiguous' === $errorCode ? 'The XML report could not be classified as malformed or truncated.' : match ($outcome) {
            'missing' => 'The retained report is unavailable.',
            'empty' => 'The report contains no '.$syntax.' document.',
            'truncated' => 'The '.$syntax.' report ends before its document is complete.',
            'unsupported' => 'JSON' === $syntax
                ? 'The JSON document is not a supported report of the declared format or schema version.'
                : 'The XML report uses an unsupported structure or declaration.',
            'oversized' => 'The report exceeds a parser limit.',
            default => 'The report is not a well-formed '.$syntax.' document.',
        });
    }
}
