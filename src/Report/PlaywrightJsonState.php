<?php

declare(strict_types=1);

namespace App\Report;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

/** Per-document Playwright JSON normalization state. */
#[Exclude]
final class PlaywrightJsonState
{
    private const MAX_ATTACHMENTS = 16;
    private const MAX_GLOBAL_ERRORS = 16;
    private const MAX_PROJECTS = 32;
    private const MAX_TITLE_PATH = 32;

    private array $suites = [];
    private array $cases = [];
    private array $attempts = [];
    private array $children = [];
    private array $observed = ['tests' => 0, 'expected' => 0, 'unexpected' => 0, 'flaky' => 0, 'skipped' => 0, 'unknown' => 0, 'expected_failures' => 0];
    private array $attemptCounts = ['attempts' => 0, 'passed' => 0, 'failed' => 0, 'timedOut' => 0, 'skipped' => 0, 'interrupted' => 0, 'unknown' => 0];

    public function __construct(private readonly JsonDocument $document)
    {
    }

    public function read(array $report, array $config, bool $rootDirKnown, array $parser): ParsedReport
    {
        foreach ($report['suites'] as $suite) {
            $this->suite($suite, null, [], 'file');
        }
        $declared = $this->declaredCounts($report['stats'] ?? null);
        foreach (['expected', 'unexpected', 'flaky', 'skipped'] as $key) {
            if (null !== $declared && null !== $declared[$key] && $declared[$key] !== $this->observed[$key]) {
                $this->document->limitation('declared_counts_mismatch', true);
            }
        }
        $summary = [
            'valid_document' => true,
            'declared_counts' => $declared ?? ['expected' => null, 'unexpected' => null, 'flaky' => null, 'skipped' => null],
            'observed_counts' => $this->observed,
            'attempt_counts' => $this->attemptCounts,
            'retry_history' => 'native',
            'playwright_version' => $this->bounded($config['version'] ?? null, 64),
            'projects' => $this->projects($config['projects'] ?? null),
            'shard' => $this->shard($config['shard'] ?? null),
            'shard_completeness' => ['status' => 'unknown', 'reason' => 'no_execution_expectation'],
            'root_dir_known' => $rootDirKnown,
            'stats' => $this->stats($report['stats'] ?? null),
            'workers' => $this->workers($config),
            'global_errors' => $this->globalErrors($report['errors'] ?? null),
        ];

        return new ParsedReport(
            $this->document->isPartial() ? 'partial' : ([] === $this->cases ? 'empty' : 'parsed'),
            $this->cases, $this->suites, $summary, $this->document->limitations(), $parser, $this->attempts,
        );
    }

    private function suite(mixed $suite, ?string $parent, array $titlePath, string $kind): void
    {
        if (!JsonDocument::isObject($suite)) {
            $this->document->limitation('native_structure_not_normalized', true);

            return;
        }
        if (count($this->suites) >= $this->document->limits()->maxSuites) {
            throw new ReportParseException('oversized', 'report_limit_exceeded');
        }
        $truncated = false;
        $key = $this->nextKey($parent, 'suite');
        $name = $this->document->text($suite['title'] ?? null, $truncated);
        $this->suites[] = [
            'suite_key' => $key, 'parent_key' => $parent, 'kind' => $kind, 'name' => $name,
            'file' => $this->document->path($suite['file'] ?? null, $truncated),
            'line' => $this->document->integer($suite['line'] ?? null),
            'truncated' => $truncated,
        ];
        // A file suite's title is the file itself, not part of a test's title path (as UXor's summary names it).
        $childPath = 'file' === $kind ? $titlePath : [...$titlePath, $name ?? ''];
        foreach (JsonDocument::listOf($suite['specs'] ?? null) as $spec) {
            $this->spec($spec, $key, $childPath);
        }
        foreach (JsonDocument::listOf($suite['suites'] ?? null) as $child) {
            $this->suite($child, $key, $childPath, 'describe');
        }
    }

    private function spec(mixed $spec, string $suiteKey, array $titlePath): void
    {
        if (!JsonDocument::isObject($spec)) {
            $this->document->limitation('native_structure_not_normalized', true);

            return;
        }
        if ([] !== JsonDocument::listOf($spec['tags'] ?? null)) {
            $this->document->limitation('native_metadata_not_normalized', false);
        }
        foreach (JsonDocument::listOf($spec['tests'] ?? null) as $test) {
            if (!JsonDocument::isObject($test)) {
                $this->document->limitation('native_structure_not_normalized', true);
                continue;
            }
            $this->test($spec, $test, $suiteKey, $titlePath);
        }
    }

    private function test(array $spec, array $test, string $suiteKey, array $titlePath): void
    {
        if (count($this->cases) >= $this->document->limits()->maxCases) {
            throw new ReportParseException('oversized', 'report_limit_exceeded');
        }
        $truncated = false;
        $caseKey = $this->nextKey($suiteKey, 'case');
        $specId = $this->document->text($spec['id'] ?? null, $truncated, 256);
        $projectName = is_string($test['projectName'] ?? null) && '' !== $test['projectName'] ? $test['projectName'] : null;
        $projectId = is_string($test['projectId'] ?? null) && '' !== $test['projectId'] ? $test['projectId'] : null;
        $project = $this->document->text($projectName ?? $projectId, $truncated, 256);
        $path = [];
        foreach (array_slice($titlePath, 0, self::MAX_TITLE_PATH) as $title) {
            $path[] = $this->document->text($title, $truncated, 1_024) ?? '';
        }
        if (count($titlePath) > self::MAX_TITLE_PATH) {
            $truncated = true;
            $this->document->limitation('native_fields_truncated', true);
        }
        $expectedStatus = $this->status($test['expectedStatus'] ?? null);
        $nativeOutcome = is_string($test['status'] ?? null) && in_array($test['status'], PlaywrightJsonAdapter::OUTCOMES, true) ? $test['status'] : 'unknown';
        if ('unknown' === $nativeOutcome) {
            $this->document->limitation('native_status_unrecognized', true);
        }
        $expectedFailure = null !== $expectedStatus && !in_array($expectedStatus, ['passed', 'skipped', 'unknown'], true);
        if ([] !== JsonDocument::listOf($test['annotations'] ?? null)) {
            $this->document->limitation('native_metadata_not_normalized', false);
        }

        $results = JsonDocument::listOf($test['results'] ?? null);
        $duration = null;
        $attemptTruncated = false;
        foreach ($results as $index => $result) {
            $attempt = $this->attempt($result, $caseKey, $index, $expectedStatus);
            if (null !== $attempt['duration_ms']) {
                $duration = ($duration ?? 0) + $attempt['duration_ms'];
            }
            $attemptTruncated = $attemptTruncated || $attempt['truncated'];
        }

        $this->cases[] = [
            'case_key' => $caseKey, 'suite_key' => $suiteKey,
            'name' => $this->document->text($spec['title'] ?? null, $truncated),
            'title_path' => $path,
            // The producer summaries name a test by its describe titles and its own title, joined with " › ".
            'full_title' => $this->fullTitle($path, $spec['title'] ?? null, $truncated),
            'file' => $this->document->path($spec['file'] ?? null, $truncated),
            'line' => $this->document->integer($spec['line'] ?? null),
            'column' => $this->document->integer($spec['column'] ?? null),
            'project' => $project,
            'project_id' => $this->document->text($projectId, $truncated, 256),
            'spec_id' => $specId,
            'test_id' => null === $specId ? null : $specId.'@'.($project ?? ''),
            'expected_status' => $expectedStatus,
            'native_outcome' => $nativeOutcome,
            'expected_failure' => $expectedFailure,
            'attempt_count' => count($results),
            'duration_ms' => $duration,
            'truncated' => $truncated || $attemptTruncated,
            'annotations' => [],
        ];
        ++$this->observed['tests'];
        ++$this->observed[$nativeOutcome];
        if ($expectedFailure) {
            ++$this->observed['expected_failures'];
        }
    }

    private function attempt(mixed $result, string $caseKey, int $index, ?string $expectedStatus): array
    {
        if (count($this->attempts) >= $this->document->limits()->maxCases * 8) {
            throw new ReportParseException('oversized', 'report_limit_exceeded');
        }
        $result = JsonDocument::isObject($result) ? $result : [];
        if ([] === $result) {
            $this->document->limitation('native_structure_not_normalized', true);
        }
        $truncated = false;
        $status = $this->status($result['status'] ?? null) ?? 'unknown';
        $errors = JsonDocument::listOf($result['errors'] ?? null);
        $nativeError = JsonDocument::isObject($result['error'] ?? null) ? $result['error'] : null;
        $nativeError ??= JsonDocument::isObject($errors[0] ?? null) ? $errors[0] : null;
        $error = null;
        if (null !== $nativeError) {
            $errorTruncated = false;
            $location = $this->document->location($result['errorLocation'] ?? null, $errorTruncated)
                ?? $this->document->location($nativeError['location'] ?? null, $errorTruncated)
                ?? $this->firstLocation($errors, $errorTruncated);
            $error = [
                'message' => $this->document->message($nativeError['message'] ?? $nativeError['value'] ?? null, $errorTruncated),
                'location' => $location,
                'truncated' => $errorTruncated,
            ];
            $truncated = $truncated || $errorTruncated;
        }
        $attachments = [];
        $nativeAttachments = JsonDocument::listOf($result['attachments'] ?? null);
        foreach (array_slice($nativeAttachments, 0, self::MAX_ATTACHMENTS) as $attachment) {
            $attachment = JsonDocument::isObject($attachment) ? $attachment : [];
            $hasBody = isset($attachment['body']);
            if ($hasBody) {
                $this->document->limitation('native_metadata_not_normalized', false);
            }
            $attachments[] = [
                'name' => $this->document->text($attachment['name'] ?? null, $truncated, 256),
                'content_type' => $this->document->text($attachment['contentType'] ?? null, $truncated, 256),
                'has_path' => is_string($attachment['path'] ?? null) && '' !== $attachment['path'],
                'has_body' => $hasBody,
            ];
        }
        if (count($nativeAttachments) > self::MAX_ATTACHMENTS) {
            $truncated = true;
            $this->document->limitation('native_metadata_not_normalized', false);
        }
        $steps = $result['steps'] ?? null;
        if ([] !== JsonDocument::listOf($steps) || [] !== JsonDocument::listOf($result['annotations'] ?? null)) {
            $this->document->limitation('native_metadata_not_normalized', false);
        }
        $stdout = $this->output($result['stdout'] ?? null);
        $stderr = $this->output($result['stderr'] ?? null);
        $attempt = [
            'attempt_key' => $caseKey.'/attempt/'.$index,
            'case_key' => $caseKey,
            'index' => $index,
            'retry' => $this->document->integer($result['retry'] ?? null),
            'status' => $status,
            'matches_expected' => 'unknown' === $status || null === $expectedStatus || 'unknown' === $expectedStatus ? null : $status === $expectedStatus,
            'duration_ms' => $this->document->milliseconds($result['duration'] ?? null),
            'start_time' => $this->document->timestamp($result['startTime'] ?? null),
            'worker_index' => $this->document->integer($result['workerIndex'] ?? null, -1),
            'parallel_index' => $this->document->integer($result['parallelIndex'] ?? null, -1),
            'error' => $error,
            'errors_count' => count($errors),
            'stdout' => $stdout,
            'stderr' => $stderr,
            'attachments' => $attachments,
            'attachments_count' => count($nativeAttachments),
            'steps_count' => is_array($steps) && array_is_list($steps) ? count($steps) : null,
            'truncated' => $truncated || $stdout['truncated'] || $stderr['truncated'],
        ];
        $this->attempts[] = $attempt;
        ++$this->attemptCounts['attempts'];
        ++$this->attemptCounts[$status];

        return $attempt;
    }

    private function fullTitle(array $path, mixed $title, bool &$truncated): ?string
    {
        if (!is_string($title)) {
            return null;
        }

        return $this->document->text(implode(' › ', [...$path, $title]), $truncated);
    }

    private function firstLocation(array $errors, bool &$truncated): ?array
    {
        foreach ($errors as $error) {
            if (JsonDocument::isObject($error) && null !== ($location = $this->document->location($error['location'] ?? null, $truncated))) {
                return $location;
            }
        }

        return null;
    }

    /** Native stdout/stderr entries ({text} or base64 {buffer}), joined and bounded; buffers are not decoded. */
    private function output(mixed $entries): array
    {
        $joined = '';
        $bytes = 0;
        $binary = false;
        foreach (JsonDocument::listOf($entries) as $entry) {
            if (is_string($entry)) {
                $entry = ['text' => $entry];
            }
            if (is_array($entry) && is_string($entry['text'] ?? null)) {
                $bytes += strlen($entry['text']);
                $joined .= $entry['text'];
            } elseif (is_array($entry) && is_string($entry['buffer'] ?? null)) {
                // Decoded length of the base64 payload, without decoding it.
                $encoded = rtrim($entry['buffer'], "=\r\n");
                $bytes += intdiv(strlen($encoded) * 3, 4);
                $binary = true;
                $this->document->limitation('binary_output_not_normalized', false);
            } else {
                $this->document->limitation('native_structure_not_normalized', true);
            }
        }
        $truncated = false;
        $text = $this->document->message($joined, $truncated) ?? '';

        return ['text' => $text, 'bytes' => $bytes, 'truncated' => $truncated || $binary];
    }

    private function status(mixed $value): ?string
    {
        if (null === $value) {
            return null;
        }
        if (is_string($value) && in_array($value, PlaywrightJsonAdapter::STATUSES, true)) {
            return $value;
        }
        $this->document->limitation('native_status_unrecognized', true);

        return 'unknown';
    }

    private function declaredCounts(mixed $stats): ?array
    {
        if (!JsonDocument::isObject($stats)) {
            return null;
        }
        $counts = [];
        foreach (['expected', 'unexpected', 'flaky', 'skipped'] as $key) {
            $counts[$key] = $this->document->integer($stats[$key] ?? null);
        }

        return $counts;
    }

    private function stats(mixed $stats): ?array
    {
        if (!JsonDocument::isObject($stats)) {
            return null;
        }

        return ['start_time' => $this->document->timestamp($stats['startTime'] ?? null), 'duration_ms' => $this->document->milliseconds($stats['duration'] ?? null)];
    }

    private function workers(array $config): ?array
    {
        $metadata = JsonDocument::isObject($config['metadata'] ?? null) ? $config['metadata'] : [];
        if (!isset($config['workers']) && !isset($metadata['actualWorkers'])) {
            return null;
        }

        return ['configured' => $this->document->integer($config['workers'] ?? null), 'actual' => $this->document->integer($metadata['actualWorkers'] ?? null)];
    }

    private function shard(mixed $shard): ?array
    {
        if (!JsonDocument::isObject($shard)) {
            return null;
        }
        $current = $this->document->integer($shard['current'] ?? null, 1);
        $total = $this->document->integer($shard['total'] ?? null, 1);

        return null === $current || null === $total ? null : ['current' => $current, 'total' => $total];
    }

    private function projects(mixed $projects): array
    {
        $names = [];
        $projects = JsonDocument::listOf($projects);
        foreach (array_slice($projects, 0, self::MAX_PROJECTS) as $project) {
            $truncated = false;
            if (JsonDocument::isObject($project)) {
                $name = is_string($project['name'] ?? null) && '' !== $project['name'] ? $project['name'] : ($project['id'] ?? null);
                $name = $this->document->text($name, $truncated, 256);
                if (null !== $name) {
                    $names[] = $name;
                }
            }
        }
        if (count($projects) > self::MAX_PROJECTS) {
            $this->document->limitation('native_fields_truncated', true);
        }

        return $names;
    }

    private function globalErrors(mixed $errors): array
    {
        $errors = JsonDocument::listOf($errors);
        $items = [];
        foreach (array_slice($errors, 0, self::MAX_GLOBAL_ERRORS) as $error) {
            $truncated = false;
            $error = JsonDocument::isObject($error) ? $error : ['message' => is_string($error) ? $error : null];
            $items[] = [
                'message' => $this->document->message($error['message'] ?? $error['value'] ?? null, $truncated),
                'location' => $this->document->location($error['location'] ?? null, $truncated),
                'truncated' => $truncated,
            ];
        }
        if ([] !== $errors) {
            // Setup or load failures outside any test stay visible; they do not invalidate the observed tests.
            $this->document->limitation('errors_outside_tests', false);
        }

        return ['count' => count($errors), 'items' => $items, 'truncated' => count($errors) > self::MAX_GLOBAL_ERRORS];
    }

    private function bounded(mixed $value, int $maximum): ?string
    {
        $truncated = false;

        return $this->document->text($value, $truncated, $maximum);
    }

    private function nextKey(?string $parent, string $kind): string
    {
        $prefix = ($parent ?? 'root').'/'.$kind;
        $index = $this->children[$prefix] ?? 0;
        $this->children[$prefix] = $index + 1;

        return $prefix.'/'.$index;
    }
}
