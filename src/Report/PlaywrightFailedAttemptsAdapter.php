<?php

declare(strict_types=1);

namespace App\Report;

/**
 * UXor's failed-attempts.json (tools/ci/playwright-summary.mjs, schema 1). Each entry annotates a native Playwright
 * attempt, retry-recovered ones included; it is linked to the native results.json attempt rather than counted again.
 * A summary that did not read a report (not-reached, missing, invalid) keeps that producer status visible.
 */
final class PlaywrightFailedAttemptsAdapter implements ReportAdapterInterface
{
    public const PRODUCER_STATUSES = ['valid', 'missing', 'invalid', 'not-reached'];
    public const SUMMARY_OUTCOMES = ['passed', 'failed', 'flaky', 'skipped'];

    public function format(): string
    {
        return 'playwright-failed-attempts';
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
        if (1 !== ($file['schema'] ?? null) || !is_string($file['status'] ?? null) || !is_array($file['attempts'] ?? [])) {
            throw JsonDocument::unsupported();
        }
        $document = new JsonDocument($limits);
        $truncated = false;
        $status = $document->vocabulary($file['status'], self::PRODUCER_STATUSES);
        $reason = $document->message($file['reason'] ?? null, $truncated);
        $shardLabel = $document->text($file['shard'] ?? null, $truncated, 256);
        if ('valid' !== $status) {
            $document->annotate('producer_status', [], ['status' => $status, 'reason' => $reason, 'shard_label' => $shardLabel]);
        }
        foreach ($document->entries($file['attempts'] ?? null) as $attempt) {
            if (!JsonDocument::isObject($attempt)) {
                $document->limitation('native_structure_not_normalized', true);
                continue;
            }
            $document->annotate('failed_attempt', self::target($document, $attempt), self::attemptData($document, $attempt));
        }
        foreach ($document->entries($file['globalErrors'] ?? null) as $error) {
            $errorTruncated = false;
            $document->annotate('global_error', [], ['message' => $document->message(is_string($error) ? $error : null, $errorTruncated), 'shard_label' => $shardLabel, 'truncated' => $errorTruncated]);
        }

        return $document->annotationReport([
            'producer' => ['status' => $status, 'reason' => $reason],
            'counts' => self::counts($document, $file['counts'] ?? null, ['passed', 'failed', 'flaky', 'skipped']),
            'run' => self::run($document, $file),
            // The producer names its shard with a label ("chromium 1/2"); it is not parsed into {current, total}.
            'shard' => null,
            'shard_label' => $shardLabel,
            'runtime' => $document->stringMap($file['runtime'] ?? null),
            'projects' => $document->stringList($file['projects'] ?? null),
            'tests_listed' => count(JsonDocument::listOf($file['tests'] ?? null)),
        ], ['name' => $this->format(), 'version' => $this->version(), 'schema' => 1]);
    }

    /** What the producer names about the attempt: enough to find the one native attempt it annotates. */
    public static function target(JsonDocument $document, array $attempt): array
    {
        $truncated = false;

        return [
            'spec_id' => $document->text($attempt['specId'] ?? null, $truncated, 256),
            'test_id' => $document->text($attempt['testId'] ?? null, $truncated, 512),
            'project' => $document->text($attempt['project'] ?? null, $truncated, 256),
            'retry' => $document->integer($attempt['retry'] ?? null),
            'file' => $document->path($attempt['file'] ?? null, $truncated),
            'line' => $document->integer($attempt['line'] ?? null),
            'title' => $document->text($attempt['title'] ?? null, $truncated),
        ];
    }

    private static function attemptData(JsonDocument $document, array $attempt): array
    {
        $truncated = false;

        return [
            'status' => $document->vocabulary($attempt['status'] ?? null, PlaywrightJsonAdapter::STATUSES),
            'outcome' => $document->vocabulary($attempt['outcome'] ?? null, self::SUMMARY_OUTCOMES),
            'duration_ms' => $document->milliseconds($attempt['durationMs'] ?? null),
            'start_time' => $document->timestamp($attempt['startTime'] ?? null),
            'worker_index' => $document->integer($attempt['workerIndex'] ?? null, -1),
            'shard_label' => $document->text($attempt['shard'] ?? null, $truncated, 256),
            'error' => $document->message($attempt['error'] ?? null, $truncated),
            'error_location' => $document->location($attempt['errorLocation'] ?? null, $truncated),
            'truncated' => $truncated,
        ];
    }

    /** @param list<string> $keys */
    public static function counts(JsonDocument $document, mixed $counts, array $keys): ?array
    {
        if (!JsonDocument::isObject($counts)) {
            return null;
        }
        $result = [];
        foreach ($keys as $key) {
            $result[$key] = $document->integer($counts[$key] ?? null);
        }

        return $result;
    }

    public static function run(JsonDocument $document, array $file): array
    {
        $truncated = false;

        return [
            'sha' => $document->text($file['sha'] ?? null, $truncated, 64),
            'ref' => $document->text($file['ref'] ?? null, $truncated, 256),
            'runUrl' => $document->text($file['runUrl'] ?? null, $truncated, 512),
            'runAttempt' => $document->integer($file['runAttempt'] ?? null),
        ];
    }
}
