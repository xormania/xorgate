<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Service\ProjectRegistry;
use App\Service\ReportIntake;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Uid\Uuid;

/**
 * Real migrated PostgreSQL with controlled Playwright producer output and UXor tool-derived files
 * (tests/Fixtures/Playwright), plus genuine UXor GitHub Actions artifacts of a retry-recovered, a red and a setup-failed
 * demo shard (tests/Fixtures/Playwright/uxor-ci, each with its provenance.json).
 */
final class PlaywrightIntakeTest extends KernelTestCase
{
    private const FLAKY = 'flaky: fails on the first attempt, passes on retry';
    private const GENUINE = 'tests/Fixtures/Playwright/uxor-ci';
    private const GENUINE_GREEN = '38195645868-1-chromium-3';
    private const GENUINE_RED = '38135099353-1-webkit-3';
    private const GENUINE_NOT_REACHED = '38122728519-1-chromium-3';
    private const GENUINE_FLAKY = 'the link dialog sets allowed links, refuses other schemes, and removes links';
    private const GENUINE_FAILED = 'every page of the gallery and of the demo names an icon, so a browser asks for no /favicon.ico';

    private Connection $connection;
    private string $directory;
    private ReportIntake $intake;
    private ProjectRegistry $registry;
    private array $execution;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir().'/xorgate-playwright-'.bin2hex(random_bytes(8));
        mkdir($this->directory, 0700);
        $_ENV['XORGATE_ARTIFACT_DIR'] = $_SERVER['XORGATE_ARTIFACT_DIR'] = $this->directory;
        self::bootKernel();
        $this->connection = self::getContainer()->get(Connection::class);
        self::assertSame('test', self::$kernel->getEnvironment());
        self::assertMatchesRegularExpression('/_test[^\/]*$/', $this->connection->getDatabase(), 'Only an isolated test database may be reset.');
        $this->resetRecords();
        $this->intake = self::getContainer()->get(ReportIntake::class);
        $this->registry = self::getContainer()->get(ProjectRegistry::class);
        $project = $this->registry->register(['request_id' => self::id(), 'name' => 'Playwright intake fixtures', 'runtimes' => ['Node.js']]);
        $this->execution = $this->newExecution($project['project_id']);
    }

    protected function tearDown(): void
    {
        $this->resetRecords();
        self::ensureKernelShutdown();
        $this->removeDirectory($this->directory);
        parent::tearDown();
    }

    public function testInstalledAdaptersAreRecordedAtAdmission(): void
    {
        $formats = $this->intake->formats();
        foreach (['junit', 'playwright-json', 'playwright-failed-attempts', 'playwright-durations', 'jev-assessment', 'text-log'] as $format) {
            self::assertArrayHasKey($format, $formats);
        }
        $queued = $this->submit($this->fixture('controlled-results.json'), 'playwright-json');
        self::assertSame(['name' => 'playwright-json', 'version' => $formats['playwright-json']], $queued['parser']);
        self::assertSame(['queued_seconds' => null, 'processing_seconds' => null, 'parse_ms' => null], $queued['timings']);
        self::assertNull($queued['attempt_count']);
        $unknown = $this->submit('not a known format', 'no-such-adapter');
        self::assertSame(['name' => null, 'version' => null], $unknown['parser']);
        $this->intake->process($unknown['operation_id']);
        $unknown = $this->operation($unknown);
        self::assertSame('unsupported', $unknown['parser_outcome']);
        self::assertSame('report_unsupported', $unknown['error']['code']);
    }

    public function testNativeRetryHistoryExpectedFailuresAndShardSurvive(): void
    {
        $native = $this->complete($this->fixture('controlled-results.json'), 'playwright-json');
        self::assertSame('parsed', $native['parser_outcome']);
        self::assertTrue($native['valid_document']);
        self::assertSame(14, $native['summary']['cases']);
        self::assertSame(18, $native['attempt_count']);
        self::assertSame(0, $native['annotation_count']);
        self::assertNull($native['links']);
        self::assertSame('native', $native['summary']['retry_history']);
        // JSONB storage reorders object keys; compare key-sorted copies.
        self::assertSame(['current' => 1, 'total' => 2], self::counted($native['summary']['shard']));
        self::assertSame(['reason' => 'no_execution_expectation', 'status' => 'unknown'], self::counted($native['summary']['shard_completeness']));
        self::assertSame(0, $native['summary']['global_errors']['count']);
        self::assertIsFloat($native['timings']['queued_seconds']);
        self::assertIsFloat($native['timings']['processing_seconds']);
        self::assertIsInt($native['timings']['parse_ms']);
        self::assertGreaterThanOrEqual(0, $native['timings']['parse_ms']);

        $attempts = $this->attempts($native);
        self::assertCount(18, $attempts);
        self::assertCount(18, array_unique(array_column($attempts, 'attempt_key')));
        foreach (['controlled', 'controlled-dark'] as $project) {
            $flaky = array_values(array_filter($attempts, static fn (array $a): bool => self::FLAKY === $a['case']['name'] && $project === $a['case']['project']));
            self::assertSame([[0, 'failed', false], [1, 'passed', true]], array_map(static fn (array $a): array => [$a['retry'], $a['status'], $a['matches_expected']], $flaky),
                'A failed first attempt must survive beside the passing retry.');
            self::assertSame('flaky', $flaky[0]['case']['native_outcome']);
            $expected = array_values(array_filter($attempts, static fn (array $a): bool => 'expected failure' === $a['case']['name'] && $project === $a['case']['project']));
            self::assertCount(1, $expected);
            self::assertSame(['failed', true, true, 'failed', 'expected'], [$expected[0]['status'], $expected[0]['matches_expected'], $expected[0]['case']['expected_failure'], $expected[0]['case']['expected_status'], $expected[0]['case']['native_outcome']]);
            $unexpected = array_values(array_filter($attempts, static fn (array $a): bool => 'always fails' === $a['case']['name'] && $project === $a['case']['project']));
            self::assertSame([[0, 'failed', false], [1, 'failed', false]], array_map(static fn (array $a): array => [$a['retry'], $a['status'], $a['matches_expected']], $unexpected));
            self::assertSame('unexpected', $unexpected[0]['case']['native_outcome']);
        }
        foreach ($attempts as $attempt) {
            self::assertSame($native['artifact']['artifact_id'], $attempt['artifact_id']);
            self::assertSame($this->executionId(), $attempt['execution_id']);
            self::assertSame([], $attempt['annotations']);
        }
        $cases = $this->intake->listObservations($this->projectId(), $this->executionId(), $native['operation_id'], 100)['items'];
        self::assertSame(['expected' => 8, 'flaky' => 2, 'skipped' => 2, 'unexpected' => 2], self::counted(array_count_values(array_column($cases, 'native_outcome'))));

        // Keyset pages cover every attempt exactly once.
        $paged = [];
        $cursor = null;
        do {
            $page = $this->intake->listAttempts($this->projectId(), $this->executionId(), $native['operation_id'], 5, $cursor);
            self::assertLessThanOrEqual(5, count($page['items']));
            $paged = [...$paged, ...$page['items']];
            $cursor = $page['next_cursor'];
        } while (null !== $cursor);
        self::assertSame(array_column($attempts, 'attempt_id'), array_column($paged, 'attempt_id'));

        self::assertSame(self::normalized($this->intake->listAttempts($this->projectId(), $this->executionId(), $native['operation_id'], 100)),
            $this->cli('xorgate:attempts:list', [$native['operation_id'], '--limit' => '100']));
        self::assertSame(self::normalized($native), $this->cli('xorgate:operation:get', [$native['operation_id']]));
    }

    public function testDerivedFilesLinkToExactAttemptsWithoutDuplicatingThem(): void
    {
        $native = $this->complete($this->fixture('controlled-results.json'), 'playwright-json');
        $failed = $this->complete($this->fixture('controlled-failed-attempts.json'), 'playwright-failed-attempts');
        $jev = $this->complete($this->fixture('controlled-jev-assessment.json'), 'jev-assessment');
        $durations = $this->complete($this->fixture('controlled-durations.json'), 'playwright-durations');
        self::assertSame(18, $this->operation($native)['attempt_count'], 'Derived imports must not add native attempts.');
        self::assertSame(18, $this->countRows('report_attempt'));
        self::assertSame(0, $failed['attempt_count']);
        self::assertSame('derived', $failed['summary']['retry_history']);

        $failedAnnotations = $this->annotations($failed);
        $failedAttempts = array_values(array_filter($failedAnnotations, static fn (array $a): bool => 'failed_attempt' === $a['kind']));
        self::assertCount(6, $failedAttempts);
        self::assertSame(6, $failed['links']['linked']);
        self::assertSame(0, $failed['links']['unmatched'] + $failed['links']['ambiguous']);
        $attempts = array_column($this->attempts($native), null, 'attempt_key');
        foreach ($failedAttempts as $annotation) {
            self::assertSame('linked', $annotation['match']['status']);
            self::assertSame($native['operation_id'], $annotation['match']['target_operation_id']);
            $target = $attempts[$annotation['match']['target_attempt_key']];
            self::assertSame($annotation['target']['retry'], $target['retry']);
            self::assertSame($annotation['target']['project'], $target['case']['project']);
            self::assertSame($annotation['target']['spec_id'], $target['case']['spec_id']);
            self::assertSame('failed', $target['status']);
            self::assertContains($annotation['annotation_id'], array_column($target['annotations'], 'annotation_id'));
        }
        self::assertCount(6, array_unique(array_column(array_column($failedAttempts, 'match'), 'target_attempt_key')), 'Each failed record names a distinct native attempt.');

        $assessment = array_values(array_filter($this->annotations($jev), static fn (array $a): bool => 'jev_assessment' === $a['kind']));
        self::assertCount(1, $assessment);
        self::assertSame('linked', $assessment[0]['match']['status']);
        self::assertSame(['unavailable', 'missing_credential'], [$assessment[0]['data']['status'], $assessment[0]['data']['reason']]);
        $assessed = $attempts[$assessment[0]['match']['target_attempt_key']];
        self::assertSame([self::FLAKY, 'controlled', 0, 'failed'], [$assessed['case']['name'], $assessed['case']['project'], $assessed['retry'], $assessed['status']]);
        self::assertSame(2, $this->attempts($native, $assessed['attempt_key'])['annotation_count'], 'The failed record and the assessment both reference one attempt.');

        $timed = array_values(array_filter($this->annotations($durations), static fn (array $a): bool => 'test_duration' === $a['kind']));
        self::assertCount(14, $timed);
        foreach ($timed as $annotation) {
            self::assertSame('linked', $annotation['match']['status']);
            self::assertNull($annotation['match']['target_attempt_key']);
            self::assertNotNull($annotation['match']['target_case_key']);
        }
        self::assertCount(14, array_unique(array_column(array_column($timed, 'match'), 'target_case_key')));

        // The native page lists annotations linked to it, and the native verdicts are unchanged.
        $linked = $this->intake->listAnnotations($this->projectId(), $this->executionId(), $native['operation_id'], 100)['items'];
        self::assertCount(6 + 1 + 14, $linked);
        self::assertSame(['linked_to_native'], array_values(array_unique(array_column($linked, 'relation'))));
        $cases = $this->intake->listObservations($this->projectId(), $this->executionId(), $native['operation_id'], 100)['items'];
        self::assertSame(['expected' => 8, 'flaky' => 2, 'skipped' => 2, 'unexpected' => 2], self::counted(array_count_values(array_column($cases, 'native_outcome'))));
        self::assertSame(self::normalized($this->intake->listAnnotations($this->projectId(), $this->executionId(), $failed['operation_id'], 100)),
            $this->cli('xorgate:annotations:list', [$failed['operation_id'], '--limit' => '100']));

        // Redelivery after completion changes nothing.
        $before = [$this->countRows('report_attempt'), $this->countRows('report_annotation'), $this->annotations($failed)];
        foreach ([$native, $failed, $jev, $durations] as $operation) { $this->intake->process($operation['operation_id']); }
        self::assertSame($before, [$this->countRows('report_attempt'), $this->countRows('report_annotation'), $this->annotations($failed)]);
    }

    public function testAnnotationImportedBeforeNativeReportLinksWhenTheNativeImportCompletes(): void
    {
        $failed = $this->complete($this->fixture('controlled-failed-attempts.json'), 'playwright-failed-attempts');
        self::assertSame(['linked' => 0, 'unmatched' => 6], array_intersect_key($failed['links'], ['linked' => 0, 'unmatched' => 0]));
        foreach ($this->annotations($failed) as $annotation) {
            if ('failed_attempt' === $annotation['kind']) {
                self::assertSame(['unmatched', 'native_report_not_imported'], [$annotation['match']['status'], $annotation['match']['reason']]);
                self::assertNull($annotation['match']['target_operation_id']);
            }
        }
        self::assertSame(0, $this->countRows('report_attempt'));
        $native = $this->complete($this->fixture('controlled-results.json'), 'playwright-json');
        $relinked = $this->operation($failed);
        self::assertSame(6, $relinked['links']['linked']);
        self::assertSame(18, $native['attempt_count']);
        self::assertSame(18, $this->countRows('report_attempt'));
        foreach ($this->annotations($failed) as $annotation) {
            if ('failed_attempt' === $annotation['kind']) {
                self::assertSame('linked', $annotation['match']['status']);
                self::assertNull($annotation['match']['reason']);
                self::assertNotNull($annotation['match']['linked_at']);
            }
        }

        // A second native import of the same execution makes the same targets ambiguous rather than double counted.
        $this->complete($this->fixture('controlled-results.json'), 'playwright-json', 'second-native-copy');
        $ambiguous = $this->operation($failed);
        self::assertSame(6, $ambiguous['links']['ambiguous']);
        foreach ($this->annotations($failed) as $annotation) {
            if ('failed_attempt' === $annotation['kind']) {
                self::assertSame(['ambiguous', 'multiple_native_imports', null], [$annotation['match']['status'], $annotation['match']['reason'], $annotation['match']['target_attempt_key']]);
            }
        }
        self::assertSame(36, $this->countRows('report_attempt'));
    }

    public function testPruningOneNativeImportRelinksAgainstTheRemainingOne(): void
    {
        $failedBytes = $this->fixture('controlled-failed-attempts.json');
        $nativeBytes = $this->fixture('controlled-results.json');
        $failed = $this->complete($failedBytes, 'playwright-failed-attempts');
        $first = $this->complete($nativeBytes, 'playwright-json', 'first-native-copy');
        $second = $this->complete($nativeBytes, 'playwright-json', 'second-native-copy');
        self::assertNotSame($first['request_id'], $second['request_id']);
        self::assertSame(['linked' => 0, 'unmatched' => 0, 'ambiguous' => 6, 'not_applicable' => 0], $this->operation($failed)['links']);

        // Direct process() calls leave the dispatched messages queued; cleanup defers while async/failed work remains.
        $this->connection->executeStatement('DELETE FROM messenger_messages');
        $retention = $this->intake->capacity()['limits']['record_retention_seconds'];
        $aged = (new \DateTimeImmutable('-'.($retention + 86400).' seconds', new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.uP');
        $this->connection->executeStatement('UPDATE report_import SET completed_at = ? WHERE id = ?', [$aged, $first['operation_id']]);
        $sweep = $this->intake->cleanup();
        self::assertSame([null, 1], [$sweep['deferred'], $sweep['pruned']]);

        $relinked = $this->operation($failed);
        self::assertSame(['linked' => 6, 'unmatched' => 0, 'ambiguous' => 0, 'not_applicable' => 0], $relinked['links']);
        $annotations = $this->annotations($failed);
        self::assertCount(6, $annotations);
        foreach ($annotations as $annotation) {
            self::assertSame('linked', $annotation['match']['status']);
            self::assertSame($second['operation_id'], $annotation['match']['target_operation_id']);
            self::assertNotNull($annotation['match']['target_attempt_key']);
        }

        $this->connection->executeStatement('DELETE FROM messenger_messages');
        $this->connection->executeStatement('UPDATE report_import SET completed_at = ? WHERE id = ?', [$aged, $second['operation_id']]);
        $sweep = $this->intake->cleanup();
        self::assertSame([null, 1], [$sweep['deferred'], $sweep['pruned']]);
        self::assertSame(['linked' => 0, 'unmatched' => 6, 'ambiguous' => 0, 'not_applicable' => 0], $this->operation($failed)['links']);
        $annotations = $this->annotations($failed);
        self::assertCount(6, $annotations);
        foreach ($annotations as $annotation) {
            self::assertSame(['unmatched', 'native_report_pruned', null, null], [$annotation['match']['status'], $annotation['match']['reason'],
                $annotation['match']['target_operation_id'], $annotation['match']['target_attempt_key']]);
        }
        self::assertSame(0, $this->countRows('report_attempt'));

        $indexes = $this->connection->fetchFirstColumn("SELECT indexname FROM pg_indexes WHERE tablename = 'report_case'");
        self::assertContains('report_case_spec', $indexes);
        self::assertContains('report_case_location', $indexes);
    }

    public function testSetupFailureGlobalErrorsAndMalformedDiagnosisLeaveExecutionEvidenceVisible(): void
    {
        $notReached = $this->complete($this->fixture('not-reached-failed-attempts.json'), 'playwright-failed-attempts');
        self::assertSame('not-reached', $notReached['summary']['producer']['status']);
        $status = array_values(array_filter($this->annotations($notReached), static fn (array $a): bool => 'producer_status' === $a['kind']));
        self::assertCount(1, $status, 'Tests not reached must be visible as a producer status, not as an empty pass.');
        self::assertSame('not_applicable', $status[0]['match']['status']);

        $global = $this->complete($this->fixture('global-error-results.json'), 'playwright-json', 'global-errors');
        self::assertSame(1, $global['summary']['global_errors']['count']);
        self::assertContains('errors_outside_tests', $global['limitations']);
        self::assertSame(0, $global['summary']['cases']);

        $native = $this->complete($this->fixture('controlled-results.json'), 'playwright-json');
        $malformed = $this->complete('{"schema": 1, "status": "valid", "attempts": [', 'playwright-failed-attempts', 'broken-diagnosis');
        self::assertSame(['completed', 'malformed', false, 'report_malformed'], [$malformed['status'], $malformed['parser_outcome'], $malformed['valid_document'], $malformed['error']['code']]);
        self::assertSame([], $this->annotations($malformed));
        $brokenJev = $this->complete('{"version": 1, "status": ', 'jev-assessment', 'broken-jev');
        self::assertSame('malformed', $brokenJev['parser_outcome']);
        $current = $this->operation($native);
        self::assertSame(['parsed', 18], [$current['parser_outcome'], $current['attempt_count']]);
        self::assertCount(18, $this->attempts($current));
    }

    public function testTextLogWindowsReportHonestOffsetsAndReassembleTheOriginal(): void
    {
        $bytes = $this->fixture('controlled-server.log');
        $log = $this->complete($bytes, 'text-log', 'server-log');
        self::assertSame('parsed', $log['parser_outcome']);
        self::assertSame(strlen($bytes), $log['summary']['bytes']);
        self::assertSame(substr_count($bytes, "\n") + ("\n" === substr($bytes, -1) ? 0 : 1), $log['summary']['lines']);
        self::assertSame(0, $log['summary']['cases']);
        $artifact = $log['artifact']['artifact_id'];

        $text = '';
        $offset = 0;
        $previousEndedMidLine = false;
        for ($page = 0; $page < 100; ++$page) {
            $window = $this->intake->readLogWindow($this->projectId(), $this->executionId(), $artifact, $offset, 1000);
            self::assertSame($offset, $window['offset']);
            self::assertLessThanOrEqual(1000, $window['returned_bytes']);
            self::assertSame(substr($bytes, $offset, $window['returned_bytes']), $window['content_text']);
            self::assertFalse($window['utf8_lossy']);
            self::assertSame($previousEndedMidLine, $window['starts_mid_line']);
            self::assertSame(0 === $offset ? null : max(0, $offset - 1000), $window['previous_offset']);
            $text .= $window['content_text'];
            if ($window['eof']) {
                self::assertNull($window['next_offset']);
                self::assertFalse($window['ends_mid_line']);
                break;
            }
            self::assertSame($offset + $window['returned_bytes'], $window['next_offset']);
            self::assertSame("\n" !== substr($window['content_text'], -1), $window['ends_mid_line']);
            $previousEndedMidLine = $window['ends_mid_line'];
            $offset = $window['next_offset'];
        }
        self::assertSame($bytes, $text, 'Bounded windows reassemble the original exactly, ANSI escapes included.');
        self::assertArrayNotHasKey('path', $window);
        self::assertSame(self::normalized($this->intake->readLogWindow($this->projectId(), $this->executionId(), $artifact, 1000, 2048)),
            $this->cli('xorgate:log:read', [$artifact, '--offset' => '1000', '--limit' => '2048']));
        $end = $this->intake->readLogWindow($this->projectId(), $this->executionId(), $artifact, strlen($bytes), 10);
        self::assertSame([0, true, null, ''], [$end['returned_bytes'], $end['eof'], $end['next_offset'], $end['content_text']]);
        foreach ([[strlen($bytes) + 1, 10], [0, 32769], [0, 0], [-1, 10]] as [$badOffset, $badLimit]) {
            try {
                $this->intake->readLogWindow($this->projectId(), $this->executionId(), $artifact, $badOffset, $badLimit);
                self::fail('Out-of-range log windows must be rejected.');
            } catch (\InvalidArgumentException) { }
        }

        // A window boundary inside a multibyte character moves to the character start; invalid bytes are flagged.
        $utf8 = $this->complete("ab\u{20AC}cd\n\xFF\n", 'text-log', 'utf8-log');
        $first = $this->intake->readLogWindow($this->projectId(), $this->executionId(), $utf8['artifact']['artifact_id'], 0, 3);
        self::assertSame(['ab', 2, 2, false], [$first['content_text'], $first['returned_bytes'], $first['next_offset'], $first['utf8_lossy']]);
        foreach ([1, 2] as $tiny) {
            $euro = $this->intake->readLogWindow($this->projectId(), $this->executionId(), $utf8['artifact']['artifact_id'], 2, $tiny);
            self::assertSame(["\u{20AC}", 3, 5, false], [$euro['content_text'], $euro['returned_bytes'], $euro['next_offset'], $euro['utf8_lossy']], 'A window smaller than its first character returns that whole character.');
        }
        $rest = $this->intake->readLogWindow($this->projectId(), $this->executionId(), $utf8['artifact']['artifact_id'], 2, 100);
        self::assertSame(["\u{20AC}cd\n\u{FFFD}\n", true, true], [$rest['content_text'], $rest['utf8_lossy'], $rest['eof']]);
    }

    /**
     * Genuine UXor CI artifacts (exact bytes, digests in each provenance.json): main run 38195645868 attempt 1 (green) shard
     * chromium 3/4, where one test failed on retry 0 and passed on retry 1, and dev run 38135099353 attempt 1 (red) shard
     * webkit 3/3, where one test failed both attempts. Both shards carry the Jev assessments UXor CI uploaded. Run
     * 38122728519 attempt 1 shard chromium 3/4 failed in setup: no native report exists, only UXor's not-reached summaries.
     */
    public function testGenuineUxorCiRunsPreserveRetryRecoveryAndRedEvidence(): void
    {
        $directory = self::$kernel->getProjectDir().'/'.self::GENUINE;
        if (!is_dir($directory.'/'.self::GENUINE_GREEN) || !is_dir($directory.'/'.self::GENUINE_RED) || !is_dir($directory.'/'.self::GENUINE_NOT_REACHED)) {
            self::markTestSkipped('Genuine UXor CI artifacts are absent: '.self::GENUINE);
        }
        $green = $directory.'/'.self::GENUINE_GREEN;
        self::assertGreaterThan(65536, filesize($green.'/results.json'), 'The genuine report exceeds the inline limit and is streamed.');

        $native = $this->completeFile($green.'/results.json', 'playwright-json');
        self::assertContains($native['parser_outcome'], ['parsed', 'partial']);
        self::assertTrue($native['valid_document']);
        self::assertSame(427, $native['summary']['cases']);
        self::assertSame(428, $native['attempt_count']);
        self::assertGreaterThan($native['summary']['cases'], $native['attempt_count'], 'Retries are attempts beside their test, not extra tests.');
        self::assertSame('1.58.2', $native['summary']['playwright_version']);
        self::assertSame(['expected' => 426, 'flaky' => 1, 'skipped' => 0, 'unexpected' => 0], self::counted($native['summary']['declared_counts']));
        // UXor selects a shard's tests with its own timing plan (--test-list), so the native report records no config.shard.
        self::assertNull($native['summary']['shard'], 'No shard may be invented when the native report names none.');
        self::assertSame(['reason' => 'no_execution_expectation', 'status' => 'unknown'], self::counted($native['summary']['shard_completeness']));
        self::assertSame(0, $native['summary']['global_errors']['count']);

        $attempts = $this->allAttempts($native);
        self::assertCount(428, $attempts);
        self::assertCount(428, array_unique(array_column($attempts, 'attempt_key')));
        $flaky = array_values(array_filter($attempts, static fn (array $a): bool => 'flaky' === $a['case']['native_outcome']));
        self::assertSame([[0, 'failed', false], [1, 'passed', true]], array_map(static fn (array $a): array => [$a['retry'], $a['status'], $a['matches_expected']], $flaky),
            'The genuine failed first attempt must survive beside its passing retry.');
        self::assertSame([self::GENUINE_FLAKY, 'smoke', 'tests/e2e/editor.spec.ts', 105, 'd2f56d7d370f0ba02e91-8d02256f9849e5216112'],
            [$flaky[0]['case']['name'], $flaky[0]['case']['project'], $flaky[0]['case']['file'], $flaky[0]['case']['line'], $flaky[0]['case']['spec_id']]);
        self::assertSame($flaky[0]['case_key'], $flaky[1]['case_key']);
        self::assertSame(['expected' => 426, 'flaky' => 1], self::counted(array_count_values(array_column(array_column(array_filter($attempts, static fn (array $a): bool => 0 === $a['index']), 'case'), 'native_outcome'))));

        $failed = $this->completeFile($green.'/failed-attempts.json', 'playwright-failed-attempts');
        self::assertSame(['completed', 'parsed', 'valid'], [$failed['status'], $failed['parser_outcome'], $failed['summary']['producer']['status']]);
        self::assertSame(['failed' => 0, 'flaky' => 1, 'passed' => 426, 'skipped' => 0], self::counted($failed['summary']['counts']));
        self::assertSame('chromium 3/4', $failed['summary']['shard_label']);
        self::assertSame(0, $failed['attempt_count']);
        self::assertSame(['linked' => 1, 'unmatched' => 0, 'ambiguous' => 0], array_intersect_key($failed['links'], ['linked' => 0, 'unmatched' => 0, 'ambiguous' => 0]));
        $records = $this->annotations($failed);
        self::assertSame(['failed_attempt'], array_values(array_unique(array_column($records, 'kind'))), 'A valid summary has no producer status record.');
        self::assertCount(1, $records);
        foreach ($records as $record) {
            self::assertSame(['linked', $native['operation_id']], [$record['match']['status'], $record['match']['target_operation_id']]);
            self::assertSame($flaky[0]['attempt_key'], $record['match']['target_attempt_key'], 'The record names the failed retry-0 attempt.');
        }
        self::assertSame(428, $this->operation($native)['attempt_count'], 'Derived imports must not add native attempts.');
        self::assertSame(428, $this->countRows('report_attempt'));

        $jev = $this->completeFile($green.'/jev/attempts/01-smoke-editor-retry0/assessment.json', 'jev-assessment');
        $assessment = $this->annotations($jev);
        self::assertCount(1, $assessment);
        self::assertSame(['jev_assessment', 'linked', $flaky[0]['attempt_key']], [$assessment[0]['kind'], $assessment[0]['match']['status'], $assessment[0]['match']['target_attempt_key']]);
        self::assertSame(['assessed', null, 'unknown', 'jev-1.13.0'], [$assessment[0]['data']['status'], $assessment[0]['data']['reason'], $assessment[0]['data']['likely_cause'], $assessment[0]['data']['method']['model']]);
        self::assertSame(['38195645868', '1', '9b2fa7309786cee435503de87cf0cd5f0a9d9782'], [$assessment[0]['data']['context']['run_id'], $assessment[0]['data']['context']['run_attempt'], $assessment[0]['data']['context']['sha']]);
        self::assertSame(2, $this->attempts($native, $flaky[0]['attempt_key'])['annotation_count'], 'The failed record and the assessment both reference one attempt.');
        self::assertSame(['flaky', 'passed'], [$this->attempts($native, $flaky[1]['attempt_key'])['case']['native_outcome'], $this->attempts($native, $flaky[1]['attempt_key'])['status']],
            'The advisory assessment leaves the native verdict unchanged.');
        self::assertSame(self::normalized($this->operation($native)), $this->cli('xorgate:operation:get', [$native['operation_id']]));

        // The red shard is another run: a separate execution of the same project.
        $this->execution = $this->newExecution($this->projectId());
        $red = $directory.'/'.self::GENUINE_RED;
        $redNative = $this->completeFile($red.'/results.json', 'playwright-json');
        self::assertContains($redNative['parser_outcome'], ['parsed', 'partial']);
        self::assertSame([251, 252], [$redNative['summary']['cases'], $redNative['attempt_count']]);
        self::assertSame(['expected' => 250, 'flaky' => 0, 'skipped' => 0, 'unexpected' => 1], self::counted($redNative['summary']['declared_counts']));
        $unexpected = array_values(array_filter($this->allAttempts($redNative), static fn (array $a): bool => 'unexpected' === $a['case']['native_outcome']));
        self::assertSame([[0, 'failed', false], [1, 'failed', false]], array_map(static fn (array $a): array => [$a['retry'], $a['status'], $a['matches_expected']], $unexpected));
        self::assertSame([self::GENUINE_FAILED, 'smoke-webkit', 'passed', false], [$unexpected[0]['case']['name'], $unexpected[0]['case']['project'], $unexpected[0]['case']['expected_status'], $unexpected[0]['case']['expected_failure']],
            'An unexpected failure is distinguishable from an expected one.');
        $redFailed = $this->completeFile($red.'/failed-attempts.json', 'playwright-failed-attempts');
        self::assertSame(['valid', 'webkit 3/3'], [$redFailed['summary']['producer']['status'], $redFailed['summary']['shard_label']]);
        self::assertSame(['failed' => 1, 'flaky' => 0, 'passed' => 250, 'skipped' => 0], self::counted($redFailed['summary']['counts']));
        self::assertSame(2, $redFailed['links']['linked']);
        self::assertSame(array_column($unexpected, 'attempt_key'), array_column(array_column($this->annotations($redFailed), 'match'), 'target_attempt_key'));
        $redJev = $this->annotations($this->completeFile($red.'/jev/attempts/02-smoke-webkit-demo-app-retry1/assessment.json', 'jev-assessment'));
        self::assertSame(['linked', $unexpected[1]['attempt_key'], 'assessed', 'unknown'], [$redJev[0]['match']['status'], $redJev[0]['match']['target_attempt_key'], $redJev[0]['data']['status'], $redJev[0]['data']['likely_cause']]);
        self::assertSame([252, 'unexpected'], [$this->operation($redNative)['attempt_count'], $this->attempts($redNative, $unexpected[1]['attempt_key'])['case']['native_outcome']]);
        self::assertSame(428 + 252, $this->countRows('report_attempt'));

        // A genuine setup failure: the tests were not reached, so there is no native report to import.
        $this->execution = $this->newExecution($this->projectId());
        $setup = $directory.'/'.self::GENUINE_NOT_REACHED;
        self::assertFileDoesNotExist($setup.'/results.json');
        $notReached = $this->completeFile($setup.'/failed-attempts.json', 'playwright-failed-attempts');
        self::assertSame(['not-reached', "tests not reached: setup failed at Plan the shard's tests"], [$notReached['summary']['producer']['status'], $notReached['summary']['producer']['reason']]);
        self::assertNull($notReached['summary']['counts'], 'A shard whose tests did not run has no counts, not zero passes.');
        $status = $this->annotations($notReached);
        self::assertSame(['producer_status'], array_column($status, 'kind'));
        self::assertSame(['not-reached', 'chromium 3/4', 'not_applicable'], [$status[0]['data']['status'], $status[0]['data']['shard_label'], $status[0]['match']['status']]);
        self::assertSame(428 + 252, $this->countRows('report_attempt'), 'A setup failure adds no attempts.');
    }

    private function fixture(string $name): string
    {
        $path = self::$kernel->getProjectDir().'/tests/Fixtures/Playwright/'.$name;
        if (!is_file($path)) {
            self::markTestSkipped('Required Playwright fixture is absent: tests/Fixtures/Playwright/'.$name);
        }

        return file_get_contents($path);
    }

    private function submit(string $bytes, string $format, ?string $role = null): array
    {
        return $this->intake->submitBase64($this->projectId(), $this->executionId(), ['request_id' => self::id(), 'format' => $format,
            'report_role' => $role ?? $format, 'source' => ['tested_revision' => 'fixture-source-revision']], base64_encode($bytes));
    }

    private function complete(string $bytes, string $format, ?string $role = null): array
    {
        $queued = $this->submit($bytes, $format, $role);
        $this->intake->process($queued['operation_id']);
        $completed = $this->operation($queued);
        self::assertSame('completed', $completed['status']);

        return $completed;
    }

    /** Files over the 64 KiB inline limit are streamed, as the CLI and web upload do. */
    private function completeFile(string $path, string $format, ?string $role = null): array
    {
        $stream = fopen($path, 'rb');
        try {
            $queued = $this->intake->submit($this->projectId(), $this->executionId(), ['request_id' => self::id(), 'format' => $format,
                'report_role' => $role ?? $format, 'source' => ['tested_revision' => 'fixture-source-revision']], $stream);
        } finally {
            fclose($stream);
        }
        $this->intake->process($queued['operation_id']);
        $completed = $this->operation($queued);
        self::assertSame('completed', $completed['status']);

        return $completed;
    }

    /** Every attempt of a native import, through the keyset pages. */
    private function allAttempts(array $operation): array
    {
        $items = [];
        $cursor = null;
        for ($page = 0; $page < 100; ++$page) {
            $listed = $this->intake->listAttempts($this->projectId(), $this->executionId(), $operation['operation_id'], 100, $cursor);
            $items = [...$items, ...$listed['items']];
            if (null === ($cursor = $listed['next_cursor'])) {
                return $items;
            }
        }
        self::fail('Attempt pages did not end.');
    }

    private function attempts(array $operation, ?string $attemptKey = null): array
    {
        if (null === $attemptKey) { return $this->intake->listAttempts($this->projectId(), $this->executionId(), $operation['operation_id'], 100)['items']; }
        foreach ($this->allAttempts($operation) as $item) { if ($attemptKey === $item['attempt_key']) { return $item; } }
        self::fail('The attempt was not listed.');
    }

    private function annotations(array $operation): array
    {
        return array_values(array_filter($this->intake->listAnnotations($this->projectId(), $this->executionId(), $operation['operation_id'], 100)['items'],
            static fn (array $item): bool => 'produced' === $item['relation']));
    }

    private function cli(string $command, array $arguments): array
    {
        $tester = new CommandTester((new Application(self::$kernel))->find($command));
        $positional = array_filter($arguments, 'is_int', ARRAY_FILTER_USE_KEY);
        // Option keys are given with their leading dashes, as CommandTester expects them.
        $options = array_filter($arguments, 'is_string', ARRAY_FILTER_USE_KEY);
        $names = 'xorgate:log:read' === $command ? ['artifact-id'] : ['operation-id'];
        $input = ['project-id' => $this->projectId(), 'execution-id' => $this->executionId()] + array_combine($names, array_values($positional)) + $options;
        self::assertSame(0, $tester->execute($input, ['interactive' => false]), $tester->getDisplay());

        return json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
    }

    private static function normalized(array $value): array { return json_decode(json_encode($value, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR); }
    private static function counted(array $counts): array { ksort($counts); return $counts; }
    private function operation(array $operation): array { return $this->intake->getOperation($this->projectId(), $this->executionId(), $operation['operation_id']); }
    private function newExecution(string $projectId): array
    {
        return $this->registry->recordExecution($projectId, ['request_id' => self::id(),
            'source' => ['tested_revision' => 'fixture-source-revision'], 'producer' => ['name' => 'Playwright fixtures']]);
    }
    private function projectId(): string { return $this->execution['project_id']; }
    private function executionId(): string { return $this->execution['execution_id']; }
    private static function id(): string { return Uuid::v7()->toRfc4122(); }
    private function countRows(string $table): int { return (int) $this->connection->fetchOne('SELECT COUNT(*) FROM '.$table); }
    private function resetRecords(): void
    {
        foreach (['messenger_messages', 'report_annotation', 'report_attempt', 'report_case', 'report_suite', 'report_import', 'report_artifact', 'registry_request', 'execution_record', 'managed_project'] as $table) {
            $this->connection->executeStatement('DELETE FROM '.$table);
        }
    }
    private function removeDirectory(string $path): void
    {
        foreach (new \FilesystemIterator($path, \FilesystemIterator::SKIP_DOTS) as $entry) {
            $entry->isDir() && !$entry->isLink() ? $this->removeDirectory($entry->getPathname()) : unlink($entry->getPathname());
        }
        rmdir($path);
    }
}
