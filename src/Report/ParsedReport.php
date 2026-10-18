<?php

declare(strict_types=1);

namespace App\Report;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

#[Exclude]
final readonly class ParsedReport
{
    /**
     * Cases, suites, attempts and annotations use structural keys, not names; suite observed counts include
     * descendant cases. Attempts are native retry results; annotations are derived references to attempts or cases
     * that intake links without counting the same execution twice.
     */
    public function __construct(
        public string $outcome,
        public array $cases,
        public array $suites,
        public array $summary,
        public array $limitations,
        public array $parser,
        public array $attempts = [],
        public array $annotations = [],
    ) {
    }
}
