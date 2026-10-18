<?php

declare(strict_types=1);

namespace App\Report;

/**
 * UXor's durations.json (tools/ci/playwright-summary.mjs, schema 1): report-only timings. Each test entry annotates
 * a native case, named by project, file, line and title (the file carries no spec id). Unknown times stay null.
 */
final class PlaywrightDurationsAdapter implements ReportAdapterInterface
{
    private const MAX_ATTEMPT_TIMES = 64;

    public function format(): string
    {
        return 'playwright-durations';
    }

    public function version(): string
    {
        return '1';
    }

    public function parse(string $path, ?ReportParseLimits $limits = null): ParsedReport
    {
        return JsonDocument::parsePath($this, $path, $limits);
    }

    public function parseStream(mixed $stream, ?ReportParseLimits $limits = null): ParsedReport
    {
        $limits ??= new ReportParseLimits();
        $file = JsonDocument::decode($stream, $limits);
        if (1 !== ($file['schema'] ?? null) || !is_string($file['status'] ?? null) || !is_array($file['tests'] ?? [])) {
            throw JsonDocument::unsupported();
        }
        $document = new JsonDocument($limits);
        $truncated = false;
        $status = $document->vocabulary($file['status'], PlaywrightFailedAttemptsAdapter::PRODUCER_STATUSES);
        $unavailable = $document->message($file['unavailable'] ?? null, $truncated);
        $shardLabel = $document->text($file['shard'] ?? null, $truncated, 256);
        if ('valid' !== $status || null !== $unavailable) {
            $document->annotate('producer_status', [], ['status' => $status, 'reason' => $unavailable, 'shard_label' => $shardLabel]);
        }
        foreach (JsonDocument::listOf($file['tests'] ?? null) as $test) {
            if (!JsonDocument::isObject($test)) {
                $document->limitation('native_structure_not_normalized', true);
                continue;
            }
            $testTruncated = false;
            $target = [
                'project' => $document->text($test['project'] ?? null, $testTruncated, 256),
                'file' => $document->path($test['file'] ?? null, $testTruncated),
                'line' => $document->integer($test['line'] ?? null),
                'title' => $document->text($test['title'] ?? null, $testTruncated),
            ];
            $native = JsonDocument::listOf($test['attemptMs'] ?? null);
            $attemptMs = [];
            foreach (array_slice($native, 0, self::MAX_ATTEMPT_TIMES) as $value) {
                $attemptMs[] = $document->milliseconds($value);
            }
            if (count($native) > self::MAX_ATTEMPT_TIMES) {
                $testTruncated = true;
                $document->limitation('native_fields_truncated', true);
            }
            $document->annotate('test_duration', $target, [
                'outcome' => $document->vocabulary($test['outcome'] ?? null, PlaywrightFailedAttemptsAdapter::SUMMARY_OUTCOMES),
                'duration_ms' => $document->milliseconds($test['durationMs'] ?? null),
                'untimed_attempts' => $document->integer($test['untimedAttempts'] ?? null),
                'attempt_ms' => $attemptMs,
                'attempts' => count($native),
                'truncated' => $testTruncated,
            ]);
        }
        $workers = JsonDocument::isObject($file['workers'] ?? null) ? $file['workers'] : null;

        return $document->annotationReport([
            'producer' => ['status' => $status, 'reason' => $unavailable],
            'counts' => ['attempts' => $document->integer($file['attempts'] ?? null), 'untimed_attempts' => $document->integer($file['untimedAttempts'] ?? null)],
            'run' => PlaywrightFailedAttemptsAdapter::run($document, $file),
            'shard' => null,
            'shard_label' => $shardLabel,
            'runtime' => $document->stringMap($file['runtime'] ?? null),
            'projects' => $document->stringList($file['projects'] ?? null),
            'durations' => [
                'wall_ms' => $document->milliseconds($file['wallMs'] ?? null),
                'summed_test_ms' => $document->milliseconds($file['summedTestMs'] ?? null),
                'workers' => null === $workers ? null : ['configured' => $document->integer($workers['configured'] ?? null), 'actual' => $document->integer($workers['actual'] ?? null)],
                'files_listed' => count(JsonDocument::listOf($file['files'] ?? null)),
                'slowest_listed' => count(JsonDocument::listOf($file['slowest'] ?? null)),
            ],
        ], ['name' => $this->format(), 'version' => $this->version(), 'schema' => 1]);
    }
}
