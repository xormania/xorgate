<?php

declare(strict_types=1);

namespace App\Report;

/**
 * One UXor Jev assessment.json (tools/ci/jev-diagnosis.mjs, version 1): an advisory hypothesis about one failed
 * attempt. It annotates that attempt and never changes the native result. The request sent to the provider and the
 * provider's raw response are not normalized: only the method (model, threshold), the answers' choices and
 * confidences, the selected excerpt and the availability reason are kept. Credentials are never read.
 */
final class JevAssessmentAdapter implements ReportAdapterInterface
{
    public const STATUSES = ['assessed', 'unavailable', 'disabled', 'not_assessed'];
    private const MAX_ANSWERS = 8;

    public function format(): string
    {
        return 'jev-assessment';
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
        if (1 !== ($file['version'] ?? null) || !is_string($file['status'] ?? null) || !JsonDocument::isObject($file['attempt'] ?? null)) {
            throw JsonDocument::unsupported();
        }
        $document = new JsonDocument($limits);
        $truncated = false;
        $attempt = $file['attempt'];
        $target = PlaywrightFailedAttemptsAdapter::target($document, $attempt);
        $target['spec_id'] = self::specId($target['test_id'], $target['project']);
        $status = $document->vocabulary($file['status'], self::STATUSES);
        $reason = $document->text($file['reason'] ?? null, $truncated, 128);
        $context = JsonDocument::isObject($file['context'] ?? null) ? $file['context'] : [];
        $run = [
            'repository' => self::nonEmpty($document->text($context['GITHUB_REPOSITORY'] ?? null, $truncated, 256)),
            'sha' => self::nonEmpty($document->text($context['GITHUB_SHA'] ?? null, $truncated, 64)),
            'run_id' => self::nonEmpty($document->text($context['GITHUB_RUN_ID'] ?? null, $truncated, 64)),
            'run_attempt' => self::nonEmpty($document->text($context['GITHUB_RUN_ATTEMPT'] ?? null, $truncated, 16)),
            'shard_label' => $document->text($context['shard'] ?? null, $truncated, 256),
        ];
        $policy = JsonDocument::isObject($file['policy'] ?? null) ? $file['policy'] : null;
        $details = JsonDocument::isObject($file['details'] ?? null) ? $file['details'] : null;
        $hash = $file['input_sha256'] ?? null;
        if ([] !== array_intersect_key($file, ['request' => true, 'response' => true])) {
            // The request holds bounded log evidence and the response the provider's raw distribution.
            $document->limitation('native_metadata_not_normalized', false);
        }
        $data = [
            'version' => 1,
            'status' => $status,
            'reason' => $reason,
            'note' => $document->text($file['note'] ?? null, $truncated, 512),
            'job' => $document->text($file['job'] ?? null, $truncated, 256),
            'method' => [
                'provider' => 'jev',
                'model' => null === $policy ? null : $document->text($policy['model'] ?? null, $truncated, 64),
                'min_confidence' => null === $policy ? null : $document->number($policy['min_confidence'] ?? null),
            ],
            'likely_cause' => $document->text($file['likely_cause'] ?? null, $truncated, 128),
            'answers' => self::answers($document, $file['response'] ?? null),
            'selected_excerpt' => self::excerpt($document, $file['selected_excerpt'] ?? null),
            'details' => null === $details ? null : [
                'validation' => $document->text($details['validation'] ?? null, $truncated, 128),
                'status' => $document->integer($details['status'] ?? null),
            ],
            'error_type' => $document->text($file['error_type'] ?? null, $truncated, 128),
            'attempt' => [
                'status' => $document->vocabulary($attempt['status'] ?? null, PlaywrightJsonAdapter::STATUSES),
                'outcome' => $document->vocabulary($attempt['outcome'] ?? null, PlaywrightFailedAttemptsAdapter::SUMMARY_OUTCOMES),
                'duration_ms' => $document->milliseconds($attempt['durationMs'] ?? null),
                'start_time' => $document->timestamp($attempt['startTime'] ?? null),
                'shard_label' => $document->text($attempt['shard'] ?? null, $truncated, 256),
                'error_location' => $document->location($attempt['errorLocation'] ?? null, $truncated),
            ],
            'context' => $run,
            'input_sha256' => is_string($hash) && 1 === preg_match('/^[0-9a-f]{64}$/D', $hash) ? $hash : null,
            'elapsed_seconds' => $document->number($file['elapsed_seconds'] ?? null),
            'truncated' => $truncated,
        ];
        if (null !== $hash && null === $data['input_sha256']) {
            $document->limitation('invalid_numeric_metadata', true);
        }
        $document->annotate('jev_assessment', $target, $data);

        return $document->annotationReport([
            'producer' => ['status' => $status, 'reason' => $reason],
            'counts' => null,
            'run' => $run,
            'shard' => null,
            'shard_label' => $run['shard_label'],
            'runtime' => null,
            'advisory' => true,
        ], ['name' => $this->format(), 'version' => $this->version(), 'assessment_version' => 1]);
    }

    /** UXor names an attempt "<specId>@<project>"; Playwright spec ids contain no "@". */
    private static function specId(?string $testId, ?string $project): ?string
    {
        if (null === $testId || '' === $testId) {
            return null;
        }
        if (null !== $project && '' !== $project && str_ends_with($testId, '@'.$project)) {
            $specId = substr($testId, 0, -strlen('@'.$project));
        } else {
            $at = strpos($testId, '@');
            $specId = false === $at ? null : substr($testId, 0, $at);
        }

        return '' === $specId ? null : $specId;
    }

    private static function nonEmpty(?string $value): ?string
    {
        return '' === $value ? null : $value;
    }

    private static function answers(JsonDocument $document, mixed $response): ?array
    {
        if (!JsonDocument::isObject($response) || !JsonDocument::isObject($response['answers'] ?? null)) {
            return null;
        }
        $answers = [];
        $truncated = false;
        foreach (array_slice($response['answers'], 0, self::MAX_ANSWERS, true) as $name => $answer) {
            $answer = JsonDocument::isObject($answer) ? $answer : [];
            $answers[] = [
                'question' => $document->text((string) $name, $truncated, 64),
                'choice' => $document->text($answer['choice'] ?? null, $truncated, 128),
                'confidence' => $document->number($answer['confidence'] ?? null),
            ];
        }

        return $answers;
    }

    private static function excerpt(JsonDocument $document, mixed $excerpt): ?array
    {
        if (!JsonDocument::isObject($excerpt)) {
            return null;
        }
        $truncated = false;

        return [
            'source' => $document->vocabulary($excerpt['source'] ?? null, ['test_error', 'server_log']),
            'line_start' => $document->integer($excerpt['line_start'] ?? null),
            'line_end' => $document->integer($excerpt['line_end'] ?? null),
            'text' => $document->message($excerpt['text'] ?? null, $truncated),
            'partial' => is_bool($excerpt['partial'] ?? null) ? $excerpt['partial'] : null,
            'truncated' => $truncated,
        ];
    }
}
