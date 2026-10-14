<?php

declare(strict_types=1);

namespace App\Report;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

/** Per-document state, so shared Symfony services never retain a previous report. */
#[Exclude]
final class JUnitParseState
{
    private array $suites = [];
    private array $cases = [];
    private array $suiteStack = [];
    private array $children = [];
    private array $limitations = [];
    private array $rootDeclared = ['tests' => null, 'failures' => null, 'errors' => null, 'skipped' => null];
    private ?array $case = null;
    private ?array $diagnostic = null;
    private ?int $ignoredDepth = null;
    private bool $rootSeen = false;
    private bool $rootClosed = false;
    private bool $partial = false;
    private int $textBytes = 0;

    public function __construct(private readonly ReportParseLimits $limits)
    {
    }

    public function consume(\XMLReader $reader): void
    {
        if (null !== $this->ignoredDepth) {
            if (\XMLReader::END_ELEMENT === $reader->nodeType && $reader->depth === $this->ignoredDepth) {
                $this->ignoredDepth = null;
            }

            return;
        }
        if (\XMLReader::ELEMENT === $reader->nodeType) {
            $this->start($reader);
        } elseif (\XMLReader::END_ELEMENT === $reader->nodeType) {
            $this->end($reader);
        } elseif (in_array($reader->nodeType, [\XMLReader::TEXT, \XMLReader::CDATA, \XMLReader::WHITESPACE, \XMLReader::SIGNIFICANT_WHITESPACE], true)) {
            if (null !== $this->diagnostic) {
                $this->appendDiagnostic($reader->value);
            } elseif ('' !== trim($reader->value)) {
                $this->limitation('unhandled_text', true);
            }
        }
    }

    private function start(\XMLReader $reader): void
    {
        if ($reader->attributeCount > 64) {
            throw new ReportParseException('oversized', 'report_limit_exceeded');
        }
        $name = $reader->localName;
        if (!$this->rootSeen) {
            if (0 !== $reader->depth || '' !== $reader->namespaceURI || !in_array($name, ['testsuites', 'testsuite'], true)) {
                throw new ReportParseException('unsupported', 'report_unsupported');
            }
            $this->rootSeen = true;
            $this->rootDeclared = $this->declaredCounts($reader);
            if ('testsuites' === $name) {
                $this->rootClosed = $reader->isEmptyElement;

                return;
            }
            $this->startSuite($reader);

            return;
        }
        if (null !== $this->diagnostic) {
            // Native XML embedded in diagnostics is retained verbatim in the original bytes.
            $this->diagnostic['truncated'] = true;
            $this->ignore($reader, 'diagnostic_markup_not_normalized', true);

            return;
        }
        if ('' !== $reader->namespaceURI) {
            if (null !== $this->case) {
                $this->case['_unknown_outcome'] = true;
            }
            $this->ignore($reader, 'native_extensions_not_normalized', true);

            return;
        }
        if ('testsuite' === $name && null === $this->case && $this->atContainerDepth($reader->depth)) {
            $this->startSuite($reader);
        } elseif ('testcase' === $name && null === $this->case && $this->atContainerDepth($reader->depth)) {
            $this->startCase($reader);
        } elseif (null !== $this->case && $reader->depth === $this->case['_depth'] + 1 && in_array($name, ['failure', 'error', 'skipped'], true)) {
            $this->startDiagnostic($reader);
        } elseif (in_array($name, ['properties', 'system-out', 'system-err'], true)) {
            $this->ignore($reader, 'native_metadata_not_normalized', false);
        } else {
            if (null !== $this->case) {
                $this->case['_unknown_outcome'] = true;
            }
            $this->ignore($reader, 'native_extensions_not_normalized', true);
        }
    }

    private function atContainerDepth(int $depth): bool
    {
        return [] === $this->suiteStack ? 1 === $depth : $depth === $this->suiteStack[array_key_last($this->suiteStack)]['depth'] + 1;
    }

    private function startSuite(\XMLReader $reader): void
    {
        if (count($this->suites) >= $this->limits->maxSuites) {
            throw new ReportParseException('oversized', 'report_limit_exceeded');
        }
        $parent = [] === $this->suiteStack ? null : $this->suiteStack[array_key_last($this->suiteStack)]['key'];
        $key = $this->nextKey($parent, 'suite');
        $index = count($this->suites);
        $truncated = false;
        $this->suites[] = [
            'suite_key' => $key, 'parent_key' => $parent,
            'name' => $this->field($reader->getAttribute('name'), $truncated),
            'duration_seconds' => $this->duration($reader->getAttribute('time')),
            'declared_counts' => $this->declaredCounts($reader),
            'observed_counts' => self::zeroCounts(), 'truncated' => $truncated,
        ];
        if (!$reader->isEmptyElement) {
            $this->suiteStack[] = ['key' => $key, 'index' => $index, 'depth' => $reader->depth];
        } elseif (0 === $reader->depth) {
            $this->rootClosed = true;
        }
    }

    private function startCase(\XMLReader $reader): void
    {
        if (count($this->cases) >= $this->limits->maxCases) {
            throw new ReportParseException('oversized', 'report_limit_exceeded');
        }
        $suiteKey = [] === $this->suiteStack ? null : $this->suiteStack[array_key_last($this->suiteStack)]['key'];
        $truncated = false;
        $name = $this->field($reader->getAttribute('name'), $truncated);
        $classname = $this->field($reader->getAttribute('classname') ?? $reader->getAttribute('class'), $truncated);
        $file = $this->field($reader->getAttribute('file'), $truncated);
        $unknownOutcome = false;
        foreach (['status', 'result', 'outcome'] as $attribute) {
            if (null !== $reader->getAttribute($attribute)) {
                $unknownOutcome = true;
            }
        }
        if ($unknownOutcome) {
            $this->limitation('native_outcome_attributes_not_normalized', true);
        }
        $this->case = [
            'case_key' => $this->nextKey($suiteKey, 'case'), 'suite_key' => $suiteKey,
            'name' => $name, 'classname' => $classname, 'file' => $file,
            'line' => $this->integer($reader->getAttribute('line')),
            'assertions' => $this->integer($reader->getAttribute('assertions')),
            'duration_seconds' => $this->duration($reader->getAttribute('time')),
            'native_outcome' => 'passed', 'diagnostics' => [], 'truncated' => $truncated,
            '_depth' => $reader->depth, '_kinds' => [], '_unknown_outcome' => $unknownOutcome,
        ];
        if ($reader->isEmptyElement) {
            $this->finishCase();
        }
    }

    private function startDiagnostic(\XMLReader $reader): void
    {
        $kind = $reader->localName;
        $this->case['_kinds'][$kind] = true;
        if (count($this->case['diagnostics']) >= $this->limits->maxDiagnosticsPerCase) {
            $this->case['truncated'] = true;
            $this->ignore($reader, 'diagnostics_truncated', true);

            return;
        }
        $truncated = false;
        $type = $this->field($reader->getAttribute('type'), $truncated);
        $message = $this->field($reader->getAttribute('message'), $truncated);
        $this->diagnostic = ['kind' => $kind, 'type' => $type, 'message' => $message, 'text' => '', 'truncated' => $truncated, '_depth' => $reader->depth];
        if ($reader->isEmptyElement) {
            $this->finishDiagnostic();
        }
    }

    private function end(\XMLReader $reader): void
    {
        if (null !== $this->diagnostic && $reader->depth === $this->diagnostic['_depth']) {
            $this->finishDiagnostic();
        } elseif (null !== $this->case && $reader->depth === $this->case['_depth']) {
            $this->finishCase();
        } elseif ([] !== $this->suiteStack && $reader->depth === $this->suiteStack[array_key_last($this->suiteStack)]['depth']) {
            array_pop($this->suiteStack);
        }
        if (0 === $reader->depth) {
            $this->rootClosed = true;
        }
    }

    private function finishDiagnostic(): void
    {
        unset($this->diagnostic['_depth']);
        $this->case['truncated'] = $this->case['truncated'] || $this->diagnostic['truncated'];
        $this->case['diagnostics'][] = $this->diagnostic;
        $this->diagnostic = null;
    }

    private function finishCase(): void
    {
        $kinds = array_keys($this->case['_kinds']);
        if ($this->case['_unknown_outcome'] || count($kinds) > 1) {
            $this->case['native_outcome'] = 'unknown';
            $this->limitation('case_outcome_ambiguous', true);
        } elseif ([] !== $kinds) {
            $this->case['native_outcome'] = $kinds[0];
        }
        foreach ($this->suiteStack as $suite) {
            self::addCount($this->suites[$suite['index']]['observed_counts'], $this->case['native_outcome']);
        }
        unset($this->case['_depth'], $this->case['_kinds'], $this->case['_unknown_outcome']);
        $this->cases[] = $this->case;
        $this->case = null;
    }

    public function finish(array $parser): ParsedReport
    {
        if (!$this->rootSeen) {
            throw new ReportParseException('empty', 'report_empty');
        }
        if (!$this->rootClosed || null !== $this->case || null !== $this->diagnostic || [] !== $this->suiteStack) {
            throw new ReportParseException('truncated', 'report_truncated');
        }
        $observed = self::zeroCounts();
        foreach ($this->cases as $case) {
            self::addCount($observed, $case['native_outcome']);
        }
        foreach ($this->suites as $suite) {
            $this->compareCounts($suite['declared_counts'], $suite['observed_counts']);
        }
        $this->compareCounts($this->rootDeclared, $observed);

        return new ParsedReport(
            $this->partial ? 'partial' : ([] === $this->cases ? 'empty' : 'parsed'),
            $this->cases, $this->suites,
            ['valid_document' => true, 'declared_counts' => $this->rootDeclared, 'observed_counts' => $observed, 'retry_history' => 'not_inferred'],
            array_keys($this->limitations), $parser,
        );
    }

    private function compareCounts(array $declared, array $observed): void
    {
        foreach ($declared as $key => $count) {
            if (null !== $count && $count !== $observed[$key]) {
                $this->limitation('declared_counts_mismatch', true);
            }
        }
    }

    private function nextKey(?string $parent, string $kind): string
    {
        $prefix = ($parent ?? 'root').'/'.$kind;
        $index = $this->children[$prefix] ?? 0;
        $this->children[$prefix] = $index + 1;

        return $prefix.'/'.$index;
    }

    private function declaredCounts(\XMLReader $reader): array
    {
        $counts = [];
        foreach (['tests', 'failures', 'errors', 'skipped'] as $key) {
            $counts[$key] = $this->integer($reader->getAttribute($key));
        }

        return $counts;
    }

    private function integer(?string $value): ?int
    {
        if (null === $value) {
            return null;
        }
        if (strlen($value) > 15 || 1 !== preg_match('/^\d+$/D', $value)) {
            $this->limitation('invalid_numeric_metadata', true);

            return null;
        }

        return (int) $value;
    }

    private function duration(?string $value): ?float
    {
        if (null === $value) {
            return null;
        }
        if (strlen($value) > 64 || 1 !== preg_match('/^(?:\d+(?:\.\d*)?|\.\d+)(?:[eE][+-]?\d+)?$/D', $value) || !is_finite((float) $value)) {
            $this->limitation('invalid_numeric_metadata', true);

            return null;
        }

        return (float) $value;
    }

    private function field(?string $value, bool &$truncated): ?string
    {
        if (null === $value) {
            return null;
        }
        $result = mb_strcut($value, 0, min(1_024, $this->limits->maxFieldBytes), 'UTF-8');
        if (strlen($result) < strlen($value)) {
            $truncated = true;
            $this->limitation('native_fields_truncated', true);
        }
        $this->reserve(strlen($result));

        return $result;
    }

    private function appendDiagnostic(string $text): void
    {
        $remaining = max(0, $this->limits->maxFieldBytes - strlen($this->diagnostic['text']));
        $chunk = mb_strcut($text, 0, $remaining, 'UTF-8');
        $this->reserve(strlen($chunk));
        $this->diagnostic['text'] .= $chunk;
        if (strlen($chunk) < strlen($text)) {
            $this->diagnostic['truncated'] = true;
            $this->limitation('diagnostics_truncated', true);
        }
    }

    private function reserve(int $bytes): void
    {
        $this->textBytes += $bytes;
        if ($this->textBytes > $this->limits->maxTextBytes) {
            throw new ReportParseException('oversized', 'report_limit_exceeded');
        }
    }

    private function ignore(\XMLReader $reader, string $limitation, bool $partial): void
    {
        $this->limitation($limitation, $partial);
        if (!$reader->isEmptyElement) {
            $this->ignoredDepth = $reader->depth;
        }
    }

    private function limitation(string $code, bool $partial): void
    {
        $this->partial = $this->partial || $partial;
        if (!isset($this->limitations[$code]) && count($this->limitations) >= $this->limits->maxLimitations) {
            throw new ReportParseException('oversized', 'report_limit_exceeded');
        }
        $this->limitations[$code] = true;
    }

    private static function zeroCounts(): array
    {
        return ['tests' => 0, 'passed' => 0, 'failures' => 0, 'errors' => 0, 'skipped' => 0, 'unknown' => 0];
    }

    private static function addCount(array &$counts, string $outcome): void
    {
        ++$counts['tests'];
        ++$counts[match ($outcome) { 'failure' => 'failures', 'error' => 'errors', default => $outcome }];
    }
}
