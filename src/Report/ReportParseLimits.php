<?php

declare(strict_types=1);

namespace App\Report;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

#[Exclude]
final readonly class ReportParseLimits
{
    public function __construct(
        public int $maxBytes = 8_388_608,
        public int $maxCases = 10_000,
        public int $maxSuites = 1_000,
        public int $maxNodes = 200_000,
        public int $maxDepth = 64,
        public int $maxFieldBytes = 4_096,
        public int $maxTextBytes = 1_048_576,
        public int $maxDiagnosticsPerCase = 8,
        public int $maxLimitations = 32,
    ) {
        foreach (get_object_vars($this) as $limit) {
            if ($limit < 1) {
                throw new \InvalidArgumentException('Report parser limits must be positive.');
            }
        }
    }
}
