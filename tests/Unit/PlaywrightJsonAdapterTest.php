<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Report\ParsedReport;
use App\Report\PlaywrightJsonAdapter;
use App\Report\ReportParseException;
use App\Report\ReportParseLimits;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Controlled producer fixtures come from tests/Fixtures/Playwright (see its README and provenance files).
 * Inline JSON below is synthesized boundary evidence, not a producer-run fixture.
 */
final class PlaywrightJsonAdapterTest extends TestCase
{
    private const FIXTURES = __DIR__.'/../Fixtures/Playwright/';

    private array $paths = [];

    protected function tearDown(): void
    {
        foreach ($this->paths as $path) {
            unlink($path);
        }
        parent::tearDown();
    }

    public function testControlledRunKeepsNativeOutcomesRetriesAndShard(): void
    {
        $report = (new PlaywrightJsonAdapter())->parse($this->fixture('controlled-results.json'));
        self::assertSame('parsed', $report->outcome);
        self::assertSame(['name' => 'playwright-json', 'version' => '1'], $report->parser);
        self::assertCount(14, $report->cases);
        self::assertCount(18, $report->attempts);
        self::assertSame(['tests' => 14, 'expected' => 8, 'unexpected' => 2, 'flaky' => 2, 'skipped' => 2, 'unknown' => 0, 'expected_failures' => 2], $report->summary['observed_counts']);
        self::assertSame(['expected' => 8, 'unexpected' => 2, 'flaky' => 2, 'skipped' => 2], $report->summary['declared_counts']);
        self::assertSame(['attempts' => 18, 'passed' => 8, 'failed' => 8, 'timedOut' => 0, 'skipped' => 2, 'interrupted' => 0, 'unknown' => 0], $report->summary['attempt_counts']);
        self::assertSame(['current' => 1, 'total' => 2], $report->summary['shard']);
        self::assertSame(['status' => 'unknown', 'reason' => 'no_execution_expectation'], $report->summary['shard_completeness']);
        self::assertSame('native', $report->summary['retry_history']);
        self::assertTrue($report->summary['root_dir_known']);
        self::assertSame(0, $report->summary['global_errors']['count']);
        self::assertNotContains('declared_counts_mismatch', $report->limitations);

        $attempts = [];
        foreach ($report->attempts as $attempt) {
            $attempts[$attempt['case_key']][] = $attempt;
        }
        $flaky = array_values(array_filter($report->cases, static fn (array $case): bool => 'flaky' === $case['native_outcome']));
        self::assertCount(2, $flaky);
        foreach ($flaky as $case) {
            self::assertSame(2, $case['attempt_count']);
            self::assertFalse($case['expected_failure']);
            self::assertSame([[0, 'failed', false], [1, 'passed', true]], array_map(static fn (array $a): array => [$a['retry'], $a['status'], $a['matches_expected']], $attempts[$case['case_key']]));
            self::assertSame($case['spec_id'].'@'.$case['project'], $case['test_id']);
            $first = $attempts[$case['case_key']][0];
            self::assertSame($case['case_key'].'/attempt/0', $first['attempt_key']);
            self::assertIsString($first['error']['message']);
            self::assertStringNotContainsString("\e[", $first['error']['message'], 'Terminal escapes are removed as UXor\'s summary does.');
        }
        self::assertNotSame($flaky[0]['project'], $flaky[1]['project']);

        $expectedFailures = array_values(array_filter($report->cases, static fn (array $case): bool => $case['expected_failure']));
        self::assertCount(2, $expectedFailures);
        foreach ($expectedFailures as $case) {
            self::assertSame('expected', $case['native_outcome']);
            self::assertSame('failed', $case['expected_status']);
            self::assertSame([['failed', true]], array_map(static fn (array $a): array => [$a['status'], $a['matches_expected']], $attempts[$case['case_key']]));
        }
        $unexpected = array_values(array_filter($report->cases, static fn (array $case): bool => 'unexpected' === $case['native_outcome']));
        self::assertCount(2, $unexpected);
        foreach ($unexpected as $case) {
            self::assertFalse($case['expected_failure']);
            self::assertSame(['failed', 'failed'], array_column($attempts[$case['case_key']], 'status'));
        }

        $output = array_values(array_filter($report->attempts, static fn (array $a): bool => $a['stdout']['bytes'] > 0 && $a['stderr']['bytes'] > 0));
        self::assertNotEmpty($output);
        self::assertStringContainsString('stdout', $output[0]['stdout']['text']);
        self::assertFalse($output[0]['stdout']['truncated']);

        $nested = array_values(array_filter($report->cases, static fn (array $case): bool => ['outer describe', 'inner describe'] === $case['title_path']));
        self::assertNotEmpty($nested);
        self::assertSame('outer describe › inner describe › '.$nested[0]['name'], $nested[0]['full_title']);
        self::assertStringStartsWith('root/suite/0/suite/0/suite/0/case/', $nested[0]['case_key']);
        self::assertSame(count($report->cases), count(array_unique(array_column($report->cases, 'case_key'))));
        self::assertSame(count($report->attempts), count(array_unique(array_column($report->attempts, 'attempt_key'))));
        self::assertNoProducerPaths($report, '/workspace/controlled-playwright');
        self::assertEquals($report, (new PlaywrightJsonAdapter())->parse($this->fixture('controlled-results.json')), 'Redelivery must derive the same keys.');
    }

    public function testGlobalErrorOutsideTestsStaysVisibleWithoutCases(): void
    {
        $report = (new PlaywrightJsonAdapter())->parse($this->fixture('global-error-results.json'));
        self::assertSame('empty', $report->outcome);
        self::assertSame([], $report->cases);
        self::assertContains('errors_outside_tests', $report->limitations);
        self::assertSame(1, $report->summary['global_errors']['count']);
        self::assertSame(4, $report->summary['global_errors']['items'][0]['location']['line']);
        self::assertSame(7, $report->summary['global_errors']['items'][0]['location']['column']);
        self::assertNull($report->summary['shard']);
        self::assertNoProducerPaths($report, '/workspace/');
    }

    public function testSyntheticTextIsBoundedAndBuffersAreNotDecoded(): void
    {
        $report = $this->parse(self::document([self::nativeTest('unexpected', [[
            'retry' => 0, 'status' => 'failed', 'duration' => 1.6, 'workerIndex' => 0, 'parallelIndex' => 0, 'startTime' => '2026-10-18T03:00:00.000Z',
            'error' => ['message' => "\e[31m".str_repeat('é', 50)."\e[39m at /producer/root/a.spec.ts:1", 'location' => ['file' => '/producer/root/a.spec.ts', 'line' => 1, 'column' => 2]],
            'errors' => [['message' => 'x']],
            'stdout' => [['text' => 'hello'], ['buffer' => base64_encode('binary')]],
            'attachments' => [['name' => 'trace', 'contentType' => 'application/zip', 'path' => '/producer/root/test-results/trace.zip']],
        ]])], ['rootDir' => '/producer/root']), new ReportParseLimits(maxFieldBytes: 9));
        self::assertSame('partial', $report->outcome);
        $attempt = $report->attempts[0];
        self::assertTrue($attempt['error']['truncated']);
        self::assertLessThanOrEqual(9, strlen($attempt['error']['message']));
        self::assertTrue(mb_check_encoding($attempt['error']['message'], 'UTF-8'));
        self::assertSame(['file' => 'a.spec.ts', 'line' => 1, 'column' => 2], $attempt['error']['location']);
        self::assertSame(2, $attempt['duration_ms']);
        self::assertSame('hello', $attempt['stdout']['text']);
        self::assertSame(11, $attempt['stdout']['bytes']);
        self::assertTrue($attempt['stdout']['truncated']);
        self::assertSame([['name' => 'trace', 'content_type' => 'applicati', 'has_path' => true, 'has_body' => false]], $attempt['attachments']);
        self::assertContains('diagnostics_truncated', $report->limitations);
        self::assertContains('binary_output_not_normalized', $report->limitations);
        self::assertTrue($report->cases[0]['truncated']);
        self::assertNoProducerPaths($report, '/producer/root');
    }

    public function testSyntheticUnknownStatusesNeverBecomeOutcomes(): void
    {
        $report = $this->parse(self::document([self::nativeTest('strange', [['retry' => 0, 'status' => 'odd'], ['retry' => 'one']], 'weird')]));
        self::assertSame('partial', $report->outcome);
        self::assertSame('unknown', $report->cases[0]['native_outcome']);
        self::assertSame('unknown', $report->cases[0]['expected_status']);
        self::assertFalse($report->cases[0]['expected_failure']);
        self::assertSame(['unknown', 'unknown'], array_column($report->attempts, 'status'));
        self::assertSame([0, null], array_column($report->attempts, 'retry'));
        self::assertSame([null, null], array_column($report->attempts, 'matches_expected'));
        self::assertSame(1, $report->summary['observed_counts']['unknown']);
        self::assertSame(2, $report->summary['attempt_counts']['unknown']);
        self::assertContains('native_status_unrecognized', $report->limitations);
        self::assertContains('invalid_numeric_metadata', $report->limitations);
    }

    public function testSyntheticDeclaredObservedMismatchIsPartial(): void
    {
        $report = $this->parse(self::document([self::nativeTest('expected', [['retry' => 0, 'status' => 'passed', 'duration' => 3]])], [], ['expected' => 2, 'unexpected' => 0, 'flaky' => 0, 'skipped' => 0]));
        self::assertSame('partial', $report->outcome);
        self::assertContains('declared_counts_mismatch', $report->limitations);
        self::assertSame(2, $report->summary['declared_counts']['expected']);
        self::assertSame(1, $report->summary['observed_counts']['expected']);

        $empty = $this->parse('{"config":{},"suites":[]}');
        self::assertSame('empty', $empty->outcome);
        self::assertTrue($empty->summary['valid_document']);
        self::assertSame(['expected' => null, 'unexpected' => null, 'flaky' => null, 'skipped' => null], $empty->summary['declared_counts']);
        self::assertFalse($empty->summary['root_dir_known']);
    }

    public function testSyntheticKeysAreStructuralAndDeterministic(): void
    {
        $json = self::document([self::nativeTest('expected', [['retry' => 0, 'status' => 'passed']]), self::nativeTest('expected', [['retry' => 0, 'status' => 'passed']])]);
        $first = $this->parse($json);
        self::assertSame(['root/suite/0/case/0', 'root/suite/0/case/1'], array_column($first->cases, 'case_key'));
        self::assertSame(['root/suite/0/case/0/attempt/0', 'root/suite/0/case/1/attempt/0'], array_column($first->attempts, 'attempt_key'));
        self::assertEquals($first, $this->parse($json));
    }

    #[DataProvider('invalidDocuments')]
    public function testSyntheticInvalidDocumentsHaveSafeDistinctDiagnostics(string $json, string $outcome, string $errorCode, ?ReportParseLimits $limits = null): void
    {
        try {
            $this->parse($json, $limits);
            self::fail('This document must not yield normalized observations.');
        } catch (ReportParseException $exception) {
            self::assertSame($outcome, $exception->outcome);
            self::assertSame($errorCode, $exception->errorCode);
            self::assertStringNotContainsString('secret', $exception->getMessage());
            self::assertStringNotContainsString(sys_get_temp_dir(), $exception->getMessage());
        }
    }

    public static function invalidDocuments(): iterable
    {
        yield 'zero bytes' => ['', 'empty', 'report_empty'];
        yield 'whitespace only' => [" \n\t ", 'empty', 'report_empty'];
        yield 'invalid JSON' => ['{"config": {"secret": ', 'malformed', 'report_malformed'];
        yield 'valid JSON of another shape' => ['{"secret": "not a Playwright report"}', 'unsupported', 'report_unsupported'];
        yield 'null config' => ['{"config": null, "suites": []}', 'unsupported', 'report_unsupported'];
        yield 'top-level list' => ['[{"config": {}, "suites": []}]', 'unsupported', 'report_unsupported'];
        yield 'bytes' => ['{"config":{},"suites":[]}', 'oversized', 'report_limit_exceeded', new ReportParseLimits(maxBytes: 8)];
        yield 'depth' => ['{"config":{},"suites":[[[[[[]]]]]]}', 'oversized', 'report_limit_exceeded', new ReportParseLimits(maxDepth: 4)];
        yield 'cases' => [self::document([self::nativeTest('expected', []), self::nativeTest('expected', [])]), 'oversized', 'report_limit_exceeded', new ReportParseLimits(maxCases: 1)];
        yield 'attempts' => [self::document([self::nativeTest('flaky', array_fill(0, 9, ['retry' => 0, 'status' => 'failed']))]), 'oversized', 'report_limit_exceeded', new ReportParseLimits(maxCases: 1)];
        yield 'normalized text' => [self::document([self::nativeTest('expected', [])]), 'oversized', 'report_limit_exceeded', new ReportParseLimits(maxTextBytes: 5)];
    }

    public function testMissingLocalInputIsExplicit(): void
    {
        $this->expectException(ReportParseException::class);
        (new PlaywrightJsonAdapter())->parse('/missing-xorgate-results.json');
    }

    private static function document(array $tests, array $config = [], ?array $stats = null): string
    {
        $specs = [];
        foreach ($tests as $index => $test) {
            $specs[] = ['title' => 'test '.$index, 'ok' => true, 'id' => 'spec-'.$index, 'file' => 'a.spec.ts', 'line' => 3, 'column' => 1, 'tests' => [$test]];
        }
        $report = ['config' => $config + ['version' => '1.58.2'], 'suites' => [['title' => 'a.spec.ts', 'file' => 'a.spec.ts', 'line' => 0, 'column' => 0, 'specs' => $specs]], 'errors' => []];
        if (null !== $stats) {
            $report['stats'] = $stats;
        }

        return json_encode($report, JSON_THROW_ON_ERROR);
    }

    private static function nativeTest(string $status, array $results, string $expectedStatus = 'passed'): array
    {
        return ['projectId' => 'p', 'projectName' => 'p', 'expectedStatus' => $expectedStatus, 'status' => $status, 'results' => $results];
    }

    private static function assertNoProducerPaths(ParsedReport $report, string $root): void
    {
        $encoded = json_encode([$report->cases, $report->suites, $report->attempts, $report->summary], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        self::assertStringNotContainsString($root, $encoded, 'Producer root and absolute paths are not stored.');
    }

    private function fixture(string $name): string
    {
        $path = self::FIXTURES.$name;
        if (!is_file($path)) {
            self::markTestSkipped('Controlled fixture is absent: tests/Fixtures/Playwright/'.$name);
        }

        return $path;
    }

    private function parse(string $json, ?ReportParseLimits $limits = null): ParsedReport
    {
        $path = tempnam(sys_get_temp_dir(), 'xorgate-playwright-');
        self::assertIsString($path);
        $this->paths[] = $path;
        file_put_contents($path, $json);

        return (new PlaywrightJsonAdapter())->parse($path, $limits);
    }
}
