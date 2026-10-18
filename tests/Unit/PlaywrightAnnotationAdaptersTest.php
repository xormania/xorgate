<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Report\JevAssessmentAdapter;
use App\Report\ParsedReport;
use App\Report\PlaywrightDurationsAdapter;
use App\Report\PlaywrightFailedAttemptsAdapter;
use App\Report\ReportAdapterInterface;
use App\Report\ReportParseException;
use App\Report\ReportParseLimits;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * UXor's derived files annotate native attempts and cases; they never add cases or attempts of their own.
 * Fixtures come from UXor's real tools run on the controlled report; inline JSON is synthesized boundary evidence.
 */
final class PlaywrightAnnotationAdaptersTest extends TestCase
{
    private const FIXTURES = __DIR__.'/../Fixtures/Playwright/';
    private const TARGET_KEYS = ['spec_id', 'test_id', 'project', 'retry', 'file', 'line', 'title'];

    private array $paths = [];

    protected function tearDown(): void
    {
        foreach ($this->paths as $path) {
            unlink($path);
        }
        parent::tearDown();
    }

    public function testFailedAttemptsAnnotateEachFailedNativeAttemptOnce(): void
    {
        $report = (new PlaywrightFailedAttemptsAdapter())->parse($this->fixture('controlled-failed-attempts.json'));
        self::assertSame('parsed', $report->outcome);
        self::assertSame(['name' => 'playwright-failed-attempts', 'version' => '1', 'schema' => 1], $report->parser);
        self::assertSame([], $report->cases);
        self::assertSame([], $report->attempts);
        self::assertCount(6, $report->annotations);
        self::assertSame(['failed_attempt'], array_values(array_unique(array_column($report->annotations, 'kind'))));
        self::assertSame(['status' => 'valid', 'reason' => null], $report->summary['producer']);
        self::assertSame('derived', $report->summary['retry_history']);
        self::assertSame(6, $report->summary['annotation_counts']['failed_attempt']);
        self::assertNull($report->summary['shard']);
        self::assertSame('chromium 1/2', $report->summary['shard_label']);
        self::assertSame(['sha' => null, 'ref' => null, 'runUrl' => null, 'runAttempt' => null], $report->summary['run']);

        $identities = [];
        foreach ($report->annotations as $index => $annotation) {
            self::assertSame('root/annotation/'.$index, $annotation['annotation_key']);
            self::assertSame(self::TARGET_KEYS, array_keys($annotation['target']));
            self::assertSame($annotation['target']['spec_id'].'@'.$annotation['target']['project'], $annotation['target']['test_id']);
            self::assertIsInt($annotation['target']['retry']);
            self::assertStringNotContainsString("\e[", (string) $annotation['data']['error']);
            $identities[] = $annotation['target']['test_id'].'#'.$annotation['target']['retry'];
        }
        self::assertSame(count($identities), count(array_unique($identities)), 'Each failed attempt is annotated once.');
        $retries = array_count_values(array_map(static fn (array $a): string => $a['data']['outcome'].':'.$a['target']['retry'], $report->annotations));
        self::assertSame(2, $retries['flaky:0']);
        self::assertArrayNotHasKey('flaky:1', $retries, 'The passing retry of a flaky test is not a failed attempt.');
        self::assertSame(2, $retries['failed:0']);
        self::assertSame(2, $retries['failed:1']);
        self::assertEquals($report, (new PlaywrightFailedAttemptsAdapter())->parse($this->fixture('controlled-failed-attempts.json')));
    }

    public function testTestsNotReachedRemainVisibleAsProducerStatus(): void
    {
        $report = (new PlaywrightFailedAttemptsAdapter())->parse($this->fixture('not-reached-failed-attempts.json'));
        self::assertSame('parsed', $report->outcome);
        self::assertCount(1, $report->annotations);
        self::assertSame('producer_status', $report->annotations[0]['kind']);
        self::assertSame('not-reached', $report->annotations[0]['data']['status']);
        self::assertIsString($report->annotations[0]['data']['reason']);
        self::assertSame('not-reached', $report->summary['producer']['status']);
        self::assertNull($report->summary['counts']);
    }

    public function testDurationsTargetCasesByProjectFileLineAndTitle(): void
    {
        $report = (new PlaywrightDurationsAdapter())->parse($this->fixture('controlled-durations.json'));
        self::assertSame('parsed', $report->outcome);
        self::assertCount(14, $report->annotations);
        self::assertSame(['test_duration'], array_values(array_unique(array_column($report->annotations, 'kind'))));
        self::assertSame(18, $report->summary['counts']['attempts']);
        self::assertSame('chromium 1/2', $report->summary['shard_label']);
        foreach ($report->annotations as $annotation) {
            self::assertSame(self::TARGET_KEYS, array_keys($annotation['target']));
            self::assertNull($annotation['target']['spec_id']);
            self::assertNull($annotation['target']['retry']);
            self::assertIsString($annotation['target']['project']);
            self::assertIsString($annotation['target']['title']);
            self::assertCount($annotation['data']['attempts'], $annotation['data']['attempt_ms']);
        }
        self::assertSame(18, array_sum(array_map(static fn (array $a): int => $a['data']['attempts'], $report->annotations)));
    }

    public function testJevAssessmentIsReadableWithoutCallingJevAndKeepsNoCredential(): void
    {
        $path = $this->fixture('controlled-jev-assessment.json');
        $report = (new JevAssessmentAdapter())->parse($path);
        self::assertSame('parsed', $report->outcome);
        self::assertCount(1, $report->annotations);
        $annotation = $report->annotations[0];
        self::assertSame('jev_assessment', $annotation['kind']);
        self::assertSame('9dbaf9aafbe4b60d2403-1b948b5bbbad0134a9e6@controlled', $annotation['target']['test_id']);
        self::assertSame('9dbaf9aafbe4b60d2403-1b948b5bbbad0134a9e6', $annotation['target']['spec_id']);
        self::assertSame('controlled', $annotation['target']['project']);
        self::assertSame(0, $annotation['target']['retry']);
        self::assertSame('unavailable', $annotation['data']['status']);
        self::assertSame('missing_credential', $annotation['data']['reason']);
        self::assertSame('jev', $annotation['data']['method']['provider']);
        self::assertIsString($annotation['data']['method']['model']);
        self::assertSame(['status' => 'unavailable', 'reason' => 'missing_credential'], $report->summary['producer']);
        self::assertTrue($report->summary['advisory']);
        $encoded = json_encode($report->annotations, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('"credential"', $encoded);
        self::assertStringNotContainsString('endpoint', $encoded);
        self::assertStringNotContainsString('[REDACTED]', $encoded);
    }

    public function testSyntheticAssessedJevKeepsMethodAnswersAndExcerptOnly(): void
    {
        $report = $this->parse(new JevAssessmentAdapter(), json_encode([
            'version' => 1, 'status' => 'assessed', 'note' => 'Advisory', 'job' => 'j',
            'attempt' => ['testId' => 'abc-1@chromium', 'project' => 'chromium', 'file' => 'a.spec.ts', 'line' => 3, 'title' => 't', 'retry' => 1, 'status' => 'timedOut', 'outcome' => 'failed'],
            'context' => ['GITHUB_REPOSITORY' => 'o/r', 'GITHUB_SHA' => str_repeat('a', 40), 'GITHUB_RUN_ID' => '9', 'GITHUB_RUN_ATTEMPT' => '2', 'shard' => '1/2'],
            'policy' => ['model' => 'jev-1.13.0', 'min_confidence' => 0.65, 'connection' => ['endpoint' => 'https://secret.invalid', 'credential' => ['env' => 'SECRET_KEY']]],
            'request' => ['state' => ['candidates' => [['text' => 'secret log line']]]],
            'response' => ['model' => 'jev-1.13.0', 'answers' => ['failure_kind' => ['type' => 'choice', 'choice' => 'timing_assertion', 'confidence' => 0.8, 'probabilities' => ['secret' => 1]]]],
            'likely_cause' => 'timing_assertion',
            'selected_excerpt' => ['source' => 'server_log', 'line_start' => 4, 'line_end' => 6, 'text' => "\e[31mtimeout\e[39m", 'partial' => false],
            'input_sha256' => str_repeat('b', 64), 'elapsed_seconds' => 1.25,
        ], JSON_THROW_ON_ERROR));
        $data = $report->annotations[0]['data'];
        self::assertSame('assessed', $data['status']);
        self::assertSame(['provider' => 'jev', 'model' => 'jev-1.13.0', 'min_confidence' => 0.65], $data['method']);
        self::assertSame([['question' => 'failure_kind', 'choice' => 'timing_assertion', 'confidence' => 0.8]], $data['answers']);
        self::assertSame('timeout', $data['selected_excerpt']['text']);
        self::assertSame(['repository' => 'o/r', 'sha' => str_repeat('a', 40), 'run_id' => '9', 'run_attempt' => '2', 'shard_label' => '1/2'], $data['context']);
        self::assertSame(str_repeat('b', 64), $data['input_sha256']);
        self::assertSame('abc-1', $report->annotations[0]['target']['spec_id']);
        self::assertContains('native_metadata_not_normalized', $report->limitations);
        self::assertStringNotContainsString('secret', json_encode([$report->annotations, $report->summary], JSON_THROW_ON_ERROR));
    }

    public function testSyntheticAllGreenFailedAttemptsIsEmptyAndUnknownStatusIsPartial(): void
    {
        $empty = $this->parse(new PlaywrightFailedAttemptsAdapter(), '{"schema":1,"status":"valid","reason":null,"counts":{"passed":3,"failed":0,"flaky":0,"skipped":0},"attempts":[],"globalErrors":[]}');
        self::assertSame('empty', $empty->outcome);
        self::assertSame(['passed' => 3, 'failed' => 0, 'flaky' => 0, 'skipped' => 0], $empty->summary['counts']);

        $global = $this->parse(new PlaywrightFailedAttemptsAdapter(), '{"schema":1,"status":"valid","attempts":[],"globalErrors":["Error: \u001b[31mload failed\u001b[39m"]}');
        self::assertSame('global_error', $global->annotations[0]['kind']);
        self::assertSame('Error: load failed', $global->annotations[0]['data']['message']);

        $unknown = $this->parse(new PlaywrightFailedAttemptsAdapter(), '{"schema":1,"status":"exploded","attempts":[{"testId":"s@p","specId":"s","project":"p","retry":0,"status":"vanished"}]}');
        self::assertSame('partial', $unknown->outcome);
        self::assertSame('unknown', $unknown->summary['producer']['status']);
        self::assertSame(['producer_status', 'failed_attempt'], array_column($unknown->annotations, 'kind'));
        self::assertSame('unknown', $unknown->annotations[1]['data']['status']);
        self::assertContains('native_status_unrecognized', $unknown->limitations);
    }

    public function testSyntheticLongErrorIsTruncatedWithinFieldBound(): void
    {
        $report = $this->parse(new PlaywrightFailedAttemptsAdapter(), json_encode(['schema' => 1, 'status' => 'valid', 'attempts' => [['testId' => 's@p', 'specId' => 's', 'project' => 'p', 'retry' => 0, 'status' => 'failed', 'error' => str_repeat('x', 100)]]], JSON_THROW_ON_ERROR), new ReportParseLimits(maxFieldBytes: 16));
        self::assertSame('partial', $report->outcome);
        self::assertSame(16, strlen($report->annotations[0]['data']['error']));
        self::assertTrue($report->annotations[0]['data']['truncated']);
        self::assertContains('diagnostics_truncated', $report->limitations);
    }

    #[DataProvider('invalidDocuments')]
    public function testSyntheticInvalidDocumentsAreRefused(string $adapter, string $json, string $outcome, string $errorCode): void
    {
        try {
            $this->parse(new $adapter(), $json);
            self::fail('This document must not yield annotations.');
        } catch (ReportParseException $exception) {
            self::assertSame($outcome, $exception->outcome);
            self::assertSame($errorCode, $exception->errorCode);
        }
    }

    public static function invalidDocuments(): iterable
    {
        yield 'failed attempts whitespace' => [PlaywrightFailedAttemptsAdapter::class, "\n ", 'empty', 'report_empty'];
        yield 'failed attempts invalid JSON' => [PlaywrightFailedAttemptsAdapter::class, '{"schema":1,', 'malformed', 'report_malformed'];
        yield 'failed attempts schema 2' => [PlaywrightFailedAttemptsAdapter::class, '{"schema":2,"status":"valid","attempts":[]}', 'unsupported', 'report_unsupported'];
        yield 'failed attempts schema as string' => [PlaywrightFailedAttemptsAdapter::class, '{"schema":"1","status":"valid","attempts":[]}', 'unsupported', 'report_unsupported'];
        yield 'a Playwright report is not failed attempts' => [PlaywrightFailedAttemptsAdapter::class, '{"config":{},"suites":[]}', 'unsupported', 'report_unsupported'];
        yield 'durations schema 2' => [PlaywrightDurationsAdapter::class, '{"schema":2,"status":"valid","tests":[]}', 'unsupported', 'report_unsupported'];
        yield 'jev version 2' => [JevAssessmentAdapter::class, '{"version":2,"status":"assessed","attempt":{}}', 'unsupported', 'report_unsupported'];
        yield 'jev without attempt' => [JevAssessmentAdapter::class, '{"version":1,"status":"assessed"}', 'unsupported', 'report_unsupported'];
    }

    private function fixture(string $name): string
    {
        $path = self::FIXTURES.$name;
        if (!is_file($path)) {
            self::markTestSkipped('Controlled fixture is absent: tests/Fixtures/Playwright/'.$name);
        }

        return $path;
    }

    private function parse(ReportAdapterInterface $adapter, string $json, ?ReportParseLimits $limits = null): ParsedReport
    {
        $path = tempnam(sys_get_temp_dir(), 'xorgate-annotation-');
        self::assertIsString($path);
        $this->paths[] = $path;
        file_put_contents($path, $json);

        return $adapter->parse($path, $limits);
    }
}
