<?php

declare(strict_types=1);

namespace App\Report;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

#[Exclude]
final readonly class ParsedReport
{
    /** Cases use structural keys, not names; suite observed counts include descendant cases. */
    public function __construct(
        public string $outcome,
        public array $cases,
        public array $suites,
        public array $summary,
        public array $limitations,
        public array $parser,
    ) {
    }
}
