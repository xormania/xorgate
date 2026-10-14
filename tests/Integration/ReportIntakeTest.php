<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Exception\IntakeCapacityException;
use App\Exception\RegistryConflictException;
use App\Exception\RegistryNotFoundException;
use App\Message\NormalizeReport;
use App\Report\JUnitAdapter;
use App\Service\IntakeLimits;
use App\Service\ProjectRegistry;
use App\Service\ReportArtifactStore;
use App\Service\ReportIntake;
use Doctrine\DBAL\Connection;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\RecoverableMessageHandlingException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Process\Process;
use Symfony\Component\Uid\Uuid;

/** Real migrated PostgreSQL, native Messenger and originals from actual test producers. */
final class ReportIntakeTest extends KernelTestCase
{
    private Connection $connection;
    private string $directory;
    private ReportArtifactStore $store;
    private ReportIntake $intake;
    private array $execution;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir().'/xorgate-intake-'.bin2hex(random_bytes(8));
        mkdir($this->directory, 0700);
        $_ENV['XORGATE_ARTIFACT_DIR'] = $_SERVER['XORGATE_ARTIFACT_DIR'] = $this->directory;
        self::bootKernel();
        $this->connection = self::getContainer()->get(Connection::class);
        self::assertSame('test', self::$kernel->getEnvironment());
        self::assertMatchesRegularExpression('/_test[^\/]*$/', $this->connection->getDatabase(), 'Only an isolated test database may be reset.');
        $this->resetRecords();
        $this->store = self::getContainer()->get(ReportArtifactStore::class);
        $this->intake = self::getContainer()->get(ReportIntake::class);
        $registry = self::getContainer()->get(ProjectRegistry::class);
        $project = $registry->register(['request_id' => self::id(), 'name' => 'Intake integration fixtures', 'runtimes' => ['PHP', 'Node.js']]);
        $this->execution = $registry->recordExecution($project['project_id'], ['request_id' => self::id(),
            'source' => ['tested_revision' => 'fixture-source-revision'], 'producer' => ['name' => 'integration-fixtures']]);
    }

    protected function tearDown(): void
    {
        $this->resetRecords();
        self::ensureKernelShutdown();
        $this->removeDirectory($this->directory);
        parent::tearDown();
    }

    public function testNativeNodeAndPhpunitOriginalsNormalizeWithoutInventingExecutionVerdicts(): void
    {
        $node = file_get_contents(self::$kernel->getProjectDir().'/tests/Fixtures/JUnit/uxor-node.xml');
        $phpunitPath = self::$kernel->getProjectDir().'/tests/Fixtures/JUnit/controlled-phpunit.xml';
        self::assertFileExists($phpunitPath, 'The curated native four-outcome PHPUnit fixture must be retained.');
        $phpunit = file_get_contents($phpunitPath);
        foreach ([[$node, 15, ['passed' => 15]], [$phpunit, 4, ['passed' => 1, 'failure' => 1, 'error' => 1, 'skipped' => 1]]] as [$bytes, $count, $outcomes]) {
            $queued = $this->submit($bytes);
            self::assertSame('queued', $queued['status']);
            self::assertNull($queued['parser_outcome']);
            self::assertNull($queued['summary']['cases']);
            self::assertSame(hash('sha256', $bytes), $queued['artifact']['sha256']);
            self::assertSame(strlen($bytes), $queued['artifact']['bytes']);
            self::assertEquals($this->execution, $queued['source_context'], 'JSON object key order is not execution provenance.');
            $this->intake->process($queued['operation_id']);
            $completed = $this->operation($queued);
            self::assertSame('completed', $completed['status']);
            self::assertSame('parsed', $completed['parser_outcome']);
            self::assertTrue($completed['valid_document']);
            self::assertSame($count, $completed['summary']['cases']);
            $cases = $this->intake->listObservations($this->projectId(), $this->executionId(), $queued['operation_id'], 100)['items'];
            $actual = array_count_values(array_column($cases, 'native_outcome'));
            ksort($actual); ksort($outcomes);
            self::assertSame($outcomes, $actual);
            self::assertSame($count, count(array_unique(array_column($cases, 'case_key'))));
            self::assertSame('not_inferred', $completed['summary']['retry_history']);
            self::assertArrayNotHasKey('verdict', $completed);
            self::assertSame($bytes, $this->original($queued));
        }
    }

    public function testScopedReplayRejectsChangedEvidenceAndCannotBorrowAnotherExecutionArtifact(): void
    {
        $metadata = $this->metadata();
        $first = $this->submit(self::twoCases(), $metadata);
        self::assertSame($first, $this->submit(self::twoCases(), $metadata));
        foreach ([self::twoCases().' ', self::twoCases()] as $index => $bytes) {
            $changed = $metadata;
            if (1 === $index) { $changed['filename'] = 'different.xml'; }
            try { $this->submit($bytes, $changed); self::fail('Changed bytes or metadata cannot reuse an intake identity.'); }
            catch (RegistryConflictException) { }
        }
        $otherRole = $this->submit(self::twoCases(), array_replace($metadata, ['report_role' => 'another-producer']));
        self::assertNotSame($first['operation_id'], $otherRole['operation_id']);
        $other = self::getContainer()->get(ProjectRegistry::class)->recordExecution($this->projectId(), [
            'request_id' => self::id(), 'source' => ['tested_revision' => 'fixture-source-revision']]);
        $otherOperation = $this->intake->submitBase64($this->projectId(), $other['execution_id'], $metadata, base64_encode(self::twoCases()));
        self::assertNotSame($first['operation_id'], $otherOperation['operation_id']);
        foreach (['operation', 'artifact', 'handle'] as $kind) {
            try {
                match ($kind) {
                    'operation' => $this->intake->getOperation($this->projectId(), $other['execution_id'], $first['operation_id']),
                    'artifact' => $this->intake->getArtifact($this->projectId(), $other['execution_id'], $first['artifact']['artifact_id']),
                    'handle' => $this->intake->submitArtifact($this->projectId(), $other['execution_id'], $this->metadata(), $first['artifact']['artifact_id']),
                };
                self::fail('Evidence must remain scoped to its execution.');
            } catch (RegistryNotFoundException) { }
        }
        self::assertSame(3, $this->countRows('report_import'));
        self::assertSame(3, $this->countRows('messenger_messages'));
        $badSchema = $this->metadata(); $badSchema['schema_version'] = null;
        try { $this->submit(self::twoCases(), $badSchema); self::fail('An explicit null schema version must be rejected.'); }
        catch (\InvalidArgumentException) { }
        self::assertSame(3, $this->countRows('report_import'));
    }

    public function testActualWorkerAndCompletedRedeliveryPreserveObservationIdentity(): void
    {
        $queued = $this->submit(self::twoCases());
        $this->consumeOne();
        $completed = $this->operation($queued);
        $cases = $this->intake->listObservations($this->projectId(), $this->executionId(), $queued['operation_id']);
        self::assertSame('completed', $completed['status']);
        self::assertCount(2, $cases['items']);
        self::getContainer()->get('messenger.transport.async')->send(new Envelope(new NormalizeReport($queued['operation_id'])));
        $this->consumeOne();
        self::assertSame($completed, $this->operation($queued));
        self::assertSame($cases, $this->intake->listObservations($this->projectId(), $this->executionId(), $queued['operation_id']));
        self::assertSame(2, $this->countRows('report_case'));
        self::assertSame(0, $this->countRows('messenger_messages'));
    }

    public function testPublishedOriginalSurvivesAdmissionRollbackAndRetryReusesItWithinPhysicalBudget(): void
    {
        $bytes = self::twoCases();
        $metadata = $this->metadata();
        $limits = $this->limits(pending: 1, retained: 1, upload: strlen($bytes) + 1, storage: strlen($bytes) + 1);
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::once())->method('dispatch')->willThrowException(new \RuntimeException('Injected transport outage'));
        $broken = $this->service($limits, $bus);
        try { $this->submit($bytes, $metadata, $broken); self::fail('Admission cannot claim success without a durable queue message.'); }
        catch (\RuntimeException) { }
        self::assertSame(0, $this->countRows('report_import'));
        self::assertSame(0, $this->countRows('report_artifact'));
        self::assertSame(0, $this->countRows('messenger_messages'));
        self::assertSame(['status' => 'available', 'available' => true, 'integrity_checked' => true], $this->store->availability(hash('sha256', $bytes), strlen($bytes)));
        self::assertSame([], glob($this->directory.'/report-staging/.stage-*'));
        $recovered = $this->service($limits);
        self::assertSame('limited', $recovered->capacity()['status']);
        self::assertSame('intake_blob_limit', $recovered->capacity()['reason'], 'An exact physical file ceiling must be reported before another upload is attempted.');
        try { $this->submit($bytes.' ', $this->metadata(), $recovered); self::fail('An orphan original still consumes physical storage.'); }
        catch (IntakeCapacityException $exception) {
            $capacity = $exception->capacity;
            self::assertContains($capacity['reason'], ['intake_storage_limit', 'intake_blob_limit']);
            $usage = ['pending' => 0, 'retained' => 0, 'created_last_minute' => 0, 'reference_bytes' => 0];
            self::assertEquals($usage, array_intersect_key($capacity['usage'], $usage), 'Public storage rejection must include the operation counters used by the native capacity form.');
            self::assertSame($recovered->capacity()['limits'], $capacity['limits'], 'A physical staging rejection must retain the complete installation limits.');
            self::assertSame('physical_storage', $capacity['rejected_reservation']['measurement_scope']);
        }
        $queued = $this->submit($bytes, $metadata, $recovered);
        self::assertSame('queued', $queued['status']);
        self::assertSame(1, $this->store->usage(10)['files']);
        self::assertSame($queued, $this->submit($bytes, $metadata, $recovered), 'Equal replay bypasses a full pending/retained budget.');
        self::assertSame(1, $this->countRows('report_import'));
        self::assertSame(1, $this->countRows('messenger_messages'));

        // STDIN is a nonseekable replay: with the original filling storage, writing
        // another spool would be refused before the request could be recognized.
        $metadataPath = $this->directory.'/replay.json';
        file_put_contents($metadataPath, json_encode($metadata, JSON_THROW_ON_ERROR));
        $replay = $this->cliProcess(['xorgate:report:submit', $this->projectId(), $this->executionId(), '--input='.$metadataPath, '--file=-'], $bytes,
            ['XORGATE_INTAKE_MAX_PENDING' => '1', 'XORGATE_INTAKE_MAX_RETAINED' => '1',
                'XORGATE_INTAKE_MAX_UPLOAD_BYTES' => (string) strlen($bytes), 'XORGATE_INTAKE_MAX_STORAGE_BYTES' => (string) strlen($bytes)]);
        $replay->run();
        self::assertSame(0, $replay->getExitCode(), $replay->getErrorOutput());
        self::assertSame($queued, $this->processJson($replay));
        self::assertSame([], glob($this->directory.'/report-staging/.stage-*'));
        self::assertSame(strlen($bytes), $this->store->usage(10)['physical_bytes']);
    }

    public function testInterruptedNormalizationCommitsNoPartialCasesAndFreshLeaseNeverAcknowledgesWork(): void
    {
        $queued = $this->submit(self::twoCases());
        $this->connection->executeStatement("CREATE FUNCTION intake_test_reject_second_case() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN IF EXISTS (SELECT 1 FROM report_case WHERE operation_id = NEW.operation_id) THEN RAISE EXCEPTION 'Injected second-case commit failure'; END IF; RETURN NEW; END $$");
        $this->connection->executeStatement('CREATE TRIGGER intake_test_case_failure BEFORE INSERT ON report_case FOR EACH ROW EXECUTE FUNCTION intake_test_reject_second_case()');
        try {
            try { $this->intake->process($queued['operation_id']); self::fail('The injected database failure must prevent completion.'); }
            catch (\RuntimeException) { }
            self::assertSame('failed', $this->operation($queued)['status']);
            self::assertNull($this->operation($queued)['parser_outcome']);
            self::assertSame(0, $this->countRows('report_case'));
            self::assertSame(0, $this->countRows('report_suite'));
        } finally {
            $this->connection->executeStatement('DROP TRIGGER intake_test_case_failure ON report_case');
            $this->connection->executeStatement('DROP FUNCTION intake_test_reject_second_case()');
        }
        $this->connection->executeStatement("UPDATE report_import SET status = 'running', started_at = CURRENT_TIMESTAMP, claim_token = ? WHERE id = ?", [self::id(), $queued['operation_id']]);
        try { $this->intake->process($queued['operation_id']); self::fail('An active lease must cause recoverable redelivery, never an ACK.'); }
        catch (RecoverableMessageHandlingException) { }
        self::assertSame('running', $this->operation($queued)['status']);
        self::assertSame(0, $this->countRows('report_case'));
        // A worker died after receiving this message. The native transport must
        // redeliver when the five-minute processing lease expires, without ACKing
        // a fresh competing claim or waiting its former one-hour default.
        self::assertCount(1, iterator_to_array(self::getContainer()->get('messenger.transport.async')->get()));
        $this->connection->executeStatement("UPDATE report_import SET started_at = CURRENT_TIMESTAMP - INTERVAL '301 seconds' WHERE id = ?", [$queued['operation_id']]);
        $this->connection->executeStatement("UPDATE messenger_messages SET delivered_at = CURRENT_TIMESTAMP - INTERVAL '301 seconds' WHERE queue_name = 'async'");
        // Recovery belongs to a fresh worker process. Reusing the abandoned
        // PostgreSQL receiver also reuses its LISTEN/NOTIFY and idle polling state.
        $worker = $this->cliProcess(['messenger:consume', 'async', '--limit=1', '--time-limit=5', '--sleep=0.01', '--no-interaction']);
        $worker->run();
        self::assertSame(0, $worker->getExitCode(), substr($worker->getOutput().$worker->getErrorOutput(), 0, 2000));
        $completed = $this->operation($queued);
        self::assertSame('completed', $completed['status']);
        self::assertSame(2, $completed['summary']['cases']);
        self::assertSame(0, $this->countRows('messenger_messages'), 'The restarted worker must ACK the recovered original message.');
        $this->intake->process($queued['operation_id']);
        self::assertSame(2, $this->countRows('report_case'));
    }

    public function testPostParseMissingAndCorruptOriginalRemainVisibleWithoutErasingObservations(): void
    {
        $bytes = self::twoCases();
        $queued = $this->submit($bytes);
        $this->intake->process($queued['operation_id']);
        $cases = $this->intake->listObservations($this->projectId(), $this->executionId(), $queued['operation_id']);
        $path = $this->blobPath($queued);
        file_put_contents($path, str_repeat('x', strlen($bytes)));
        foreach (['corrupt', 'missing'] as $expected) {
            if ('missing' === $expected) { unlink($path); }
            $current = $this->operation($queued);
            self::assertSame('completed', $current['status']);
            self::assertSame('parsed', $current['parser_outcome']);
            self::assertSame($expected, $current['artifact']['availability']['status']);
            self::assertFalse($current['artifact']['availability']['available']);
            $listed = $this->intake->listEvidence($this->projectId(), $this->executionId())['items'][0];
            self::assertSame($current['operation_id'], $listed['operation_id']);
            self::assertSame('corrupt' === $expected ? 'present' : 'missing', $listed['artifact']['availability']['status']);
            self::assertFalse($listed['artifact']['availability']['integrity_checked']);
            self::assertFalse($listed['artifact']['availability']['available'], 'Listing presence must not claim verified original bytes.');
            self::assertSame($cases, $this->intake->listObservations($this->projectId(), $this->executionId(), $queued['operation_id']));
            try { $this->original($queued); self::fail('Retrieval cannot return unverified original bytes.'); }
            catch (\RuntimeException) { }
        }
    }

    public function testPrivateStorageBoundsCleanupAndSymlinkEscapeDoNotTouchUnrelatedFiles(): void
    {
        $stream = $this->stream(str_repeat('x', 65));
        try { $this->store->stage($stream, 64); self::fail('Oversized staging must be bounded.'); }
        catch (\InvalidArgumentException) { }
        finally { fclose($stream); }
        self::assertSame([], glob($this->directory.'/report-staging/.stage-*'));
        $queued = $this->submit(self::twoCases(), array_replace($this->metadata(), ['filename' => '../../display-only.xml']));
        self::assertSame('../../display-only.xml', $queued['artifact']['filename']);
        $knownPath = $this->blobPath($queued);
        $orphan = $this->store->stage($this->stream('unreferenced original'), 100);
        $relative = $this->store->publish($orphan); $this->store->discard($orphan);
        touch($this->directory.'/'.$relative, time() - 7200);
        $stale = $this->store->stage($this->stream('abandoned staging'), 100);
        touch($stale['temporary'], time() - 7200);
        $active = $this->store->stage($this->stream('slow active stream'), 100);
        touch($active['temporary'], time() - 7200);
        $activeDescriptor = fopen($active['temporary'], 'r+b');
        self::assertTrue(flock($activeDescriptor, LOCK_EX | LOCK_NB));
        touch($knownPath, time() - 7200);
        try {
            $cleaned = $this->store->cleanupOrphans([$queued['artifact']['sha256']], time() - 3600, 2);
            self::assertSame(1, $cleaned['orphan_blobs']); self::assertSame(1, $cleaned['staged_files']);
            self::assertFileExists($active['temporary'], 'Age cannot make a live locked stream reclaimable.');
        } finally { flock($activeDescriptor, LOCK_UN); fclose($activeDescriptor); }
        self::assertSame(1, $this->store->cleanupOrphans([$queued['artifact']['sha256']], time() - 3600, 2)['staged_files']);
        self::assertFileExists($knownPath);
        $owned = dirname($knownPath); $outside = $this->directory.'/unrelated';
        rename($owned, $outside); symlink($outside, $owned);
        try {
            self::assertFalse($this->store->availability($queued['artifact']['sha256'], $queued['artifact']['bytes'])['available']);
            try { $this->store->remove($queued['artifact']['sha256']); self::fail('A substituted directory must be rejected.'); }
            catch (\RuntimeException) { }
            self::assertFileExists($outside.'/'.basename($knownPath));
        } finally { unlink($owned); rename($outside, $owned); }
        try { $this->store->open('../../outside', 0); self::fail('Artifact identity cannot become a path.'); }
        catch (\InvalidArgumentException) { }
    }

    public function testConcurrentAdmissionIsAtomicAndSameRequestReplaysAtCapacity(): void
    {
        $report = $this->directory.'/input.xml'; file_put_contents($report, self::twoCases());
        foreach ([false, true] as $sameRequest) {
            $this->resetReports();
            $request = $this->metadata(); $processes = [];
            $this->connection->beginTransaction();
            $this->connection->executeQuery('SELECT pg_advisory_xact_lock(?)', [IntakeLimits::LOCK_KEY]);
            try {
                for ($index = 0; $index < 2; ++$index) {
                    $process = $this->cliProcess(['xorgate:report:submit', $this->projectId(), $this->executionId(), '--input=-', '--file='.$report],
                        json_encode($sameRequest ? $request : $this->metadata(), JSON_THROW_ON_ERROR), ['XORGATE_INTAKE_MAX_PENDING' => '1', 'XORGATE_INTAKE_MAX_RETAINED' => '2']);
                    $process->start(); $processes[] = $process;
                }
                usleep(100_000);
            } finally { $this->connection->commit(); }
            $codes = []; $ids = [];
            foreach ($processes as $process) {
                $codes[] = $process->wait(); $result = $this->processJson($process);
                if (0 === $process->getExitCode()) { $ids[] = $result['operation_id']; }
                else { self::assertSame('intake_pending_limit', $result['capacity']['reason']); }
            }
            sort($codes);
            self::assertSame($sameRequest ? [0, 0] : [0, 1], $codes);
            self::assertCount(1, array_unique($ids));
            self::assertSame(1, $this->countRows('report_import'));
            self::assertSame(1, $this->countRows('report_artifact'));
            self::assertSame(1, $this->countRows('messenger_messages'));
        }
    }

    public function testParserNegativeCompletionFreesPendingCapacityAndValidEmptyIsMeasuredZero(): void
    {
        $intake = $this->service($this->limits(pending: 1));
        $negative = $this->submit('<testsuite><testcase', $this->metadata(), $intake);
        $intake->process($negative['operation_id']);
        $failedParse = $intake->getOperation($this->projectId(), $this->executionId(), $negative['operation_id']);
        self::assertSame('completed', $failedParse['status']);
        self::assertContains($failedParse['parser_outcome'], ['malformed', 'truncated']);
        self::assertFalse($failedParse['valid_document']);
        self::assertNull($failedParse['summary']['cases']);
        self::assertSame(0, $intake->capacity()['usage']['pending']);
        $empty = $this->submit('<testsuite name="empty" tests="0"/>', $this->metadata(), $intake);
        $intake->process($empty['operation_id']);
        $empty = $intake->getOperation($this->projectId(), $this->executionId(), $empty['operation_id']);
        self::assertSame('empty', $empty['parser_outcome']);
        self::assertTrue($empty['valid_document']);
        self::assertSame(0, $empty['summary']['cases']);
        self::assertSame(0, $empty['summary']['observed_counts']['tests']);
        self::assertArrayNotHasKey('verdict', $empty);
    }

    public function testRetentionProtectsUnacknowledgedAndFailedWorkThenExpiresOnlyEligibleOriginals(): void
    {
        $intake = $this->service($this->limits());
        $old = $this->submit(self::twoCases(), $this->metadata(), $intake); $intake->process($old['operation_id']);
        $active = $this->submit(self::twoCases(), $this->metadata(), $intake);
        $this->connection->executeStatement("UPDATE report_artifact SET created_at = CURRENT_TIMESTAMP - INTERVAL '90 seconds'");
        $this->connection->executeStatement("UPDATE report_import SET completed_at = CURRENT_TIMESTAMP - INTERVAL '90 seconds' WHERE id = ?", [$old['operation_id']]);
        self::assertSame('queue_not_idle', $intake->cleanup()['deferred']);
        $this->connection->executeStatement("UPDATE messenger_messages SET queue_name = 'failed'");
        $this->connection->executeStatement("UPDATE report_import SET status = 'failed' WHERE id = ?", [$active['operation_id']]);
        self::assertSame('queue_not_idle', $intake->cleanup()['deferred']);
        $this->connection->executeStatement('DELETE FROM messenger_messages');
        $sweep = $intake->cleanup();
        self::assertSame(1, $sweep['expired']);
        self::assertSame('expired', $this->operation($old)['artifact']['availability']['status']);
        self::assertSame('available', $this->operation($active)['artifact']['availability']['status'], 'Shared bytes must survive the active reference.');
        self::assertSame('failed', $this->operation($active)['status']);
        $this->connection->executeStatement("UPDATE report_import SET completed_at = CURRENT_TIMESTAMP - INTERVAL '180 seconds' WHERE id = ?", [$old['operation_id']]);
        self::assertSame(1, $intake->cleanup()['pruned']);
        self::assertSame(1, $this->countRows('report_import'));
        self::assertSame(self::twoCases(), $this->original($active));
    }

    public function testRetentionDeletionFailureKeepsCommittedExpirationAndNextSweepReconcilesBytes(): void
    {
        $intake = $this->service($this->limits());
        $old = $this->submit(self::twoCases(), $this->metadata(), $intake);
        $intake->process($old['operation_id']);
        $path = $this->blobPath($old);
        $this->connection->executeStatement('DELETE FROM messenger_messages');
        $this->connection->executeStatement("UPDATE report_artifact SET created_at = CURRENT_TIMESTAMP - INTERVAL '90 seconds'");
        $this->connection->executeStatement("UPDATE report_import SET completed_at = CURRENT_TIMESTAMP - INTERVAL '90 seconds'");
        $owned = dirname($path); $outside = $this->directory.'/retention-blocked';
        rename($owned, $outside); symlink($outside, $owned);
        try {
            $sweep = $intake->cleanup();
            self::assertSame('storage_cleanup_unavailable', $sweep['deferred']);
            self::assertSame(1, $sweep['expired']);
            $expired = $this->operation($old)['artifact'];
            self::assertNotNull($expired['expired_at'], 'Expiration must commit before destructive file cleanup.');
            self::assertSame('expired', $expired['availability']['status']);
            self::assertFileExists($outside.'/'.basename($path), 'Unsafe deletion must not follow the substituted directory.');
        } finally { unlink($owned); rename($outside, $owned); }
        $retry = $intake->cleanup();
        self::assertNull($retry['deferred']);
        self::assertSame(0, $retry['expired'], 'A retry reconciles the committed expiry without expiring twice.');
        self::assertFileDoesNotExist($path);
        self::assertSame('expired', $this->operation($old)['artifact']['availability']['status']);
        self::assertSame(2, $this->countRows('report_case'));
    }

    public function testRetentionCatalogPruningFailureCannotRollBackExpirationAfterOriginalDeletion(): void
    {
        $intake = $this->service($this->limits());
        $old = $this->submit(self::twoCases(), $this->metadata(), $intake);
        $intake->process($old['operation_id']);
        $path = $this->blobPath($old);
        $this->connection->executeStatement('DELETE FROM messenger_messages');
        $this->connection->executeStatement("UPDATE report_artifact SET created_at = CURRENT_TIMESTAMP - INTERVAL '180 seconds'");
        $this->connection->executeStatement("UPDATE report_import SET completed_at = CURRENT_TIMESTAMP - INTERVAL '180 seconds'");
        $this->connection->executeStatement("CREATE FUNCTION intake_test_reject_catalog_prune() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN RAISE EXCEPTION 'Injected expired catalog prune failure'; END $$");
        $this->connection->executeStatement('CREATE TRIGGER intake_test_catalog_failure BEFORE DELETE ON report_artifact FOR EACH ROW EXECUTE FUNCTION intake_test_reject_catalog_prune()');
        try {
            try { $intake->cleanup(); self::fail('The injected final catalog prune failure must remain visible.'); }
            catch (\RuntimeException) { }
            $artifact = $this->intake->getArtifact($this->projectId(), $this->executionId(), $old['artifact']['artifact_id']);
            self::assertNotNull($artifact['expired_at']);
            self::assertSame('expired', $artifact['availability']['status'], 'A removed original must remain explained by committed expiration.');
            self::assertFileDoesNotExist($path);
            self::assertSame(0, $this->countRows('report_import'));
            self::assertSame(1, $this->countRows('report_artifact'));
        } finally {
            $this->connection->executeStatement('DROP TRIGGER intake_test_catalog_failure ON report_artifact');
            $this->connection->executeStatement('DROP FUNCTION intake_test_reject_catalog_prune()');
        }
        self::assertNull($intake->cleanup()['deferred']);
        self::assertSame(0, $this->countRows('report_artifact'));
        self::assertSame(0, $this->countRows('report_case'));
    }

    public function testConcurrentStagingSharesStorageBudgetAndCountsHardlinkedBytesOnce(): void
    {
        $original = $this->store->stage($this->stream(str_repeat('b', 32)), 64, 96, 4);
        $this->store->publish($original);
        self::assertSame(32, $this->store->usage(4)['physical_bytes'], 'Publication adds a hardlink, not a second inode of report bytes.');
        self::assertSame(1, $this->store->usage(4)['physical_files']);
        $this->store->discard($original);
        $script = <<<'PHP'
require 'vendor/autoload.php';
$store = new App\Service\ReportArtifactStore($argv[1]);
try {
    $staged = $store->stage(STDIN, 64, 96, 4);
    echo json_encode(['accepted' => true, 'staged' => $staged], JSON_THROW_ON_ERROR);
} catch (App\Exception\IntakeCapacityException $exception) {
    echo json_encode($exception->response(), JSON_THROW_ON_ERROR);
    exit(1);
}
PHP;
        $processes = [];
        foreach (['s', 't'] as $byte) {
            $process = new Process([PHP_BINARY, '-r', $script, $this->directory], self::$kernel->getProjectDir(), [], str_repeat($byte, 64));
            $process->setTimeout(15); $process->start(); $processes[] = $process;
        }
        $codes = []; $staged = null;
        foreach ($processes as $process) {
            $codes[] = $process->wait(); $result = $this->processJson($process);
            if (0 === $process->getExitCode()) { $staged = $result['staged']; }
            else { self::assertSame('intake_storage_limit', $result['capacity']['reason']); }
        }
        sort($codes); self::assertSame([0, 1], $codes);
        self::assertNotNull($staged);
        $usage = $this->store->usage(4);
        self::assertSame(32, $usage['bytes']);
        self::assertSame(64, $usage['staging_bytes']);
        self::assertSame(96, $usage['physical_bytes']);
        self::assertSame(2, $usage['physical_files']);
        self::assertSame(1, $usage['staging_files']);
        $this->store->discard($staged);
        self::assertSame(32, $this->store->usage(4)['physical_bytes']);
    }

    public function testCliRejectsWrappersSymlinksAndFifosBeforeReadingOrAdmitting(): void
    {
        $report = $this->directory.'/input.xml'; file_put_contents($report, self::twoCases());
        symlink($report, $this->directory.'/linked.xml');
        $fifo = new Process(['mkfifo', $this->directory.'/input.fifo']); $fifo->mustRun();
        foreach (['php://filter/resource='.$report, $this->directory.'/linked.xml', $this->directory.'/input.fifo'] as $path) {
            $process = $this->cliProcess(['xorgate:report:submit', $this->projectId(), $this->executionId(), '--input=-', '--file='.$path], json_encode($this->metadata(), JSON_THROW_ON_ERROR));
            $process->run();
            self::assertSame(2, $process->getExitCode(), $process->getErrorOutput());
            self::assertSame('invalid_input', $this->processJson($process)['error']['code']);
        }
        $metadataPath = $this->directory.'/metadata.json'; file_put_contents($metadataPath, json_encode($this->metadata(), JSON_THROW_ON_ERROR));
        symlink($metadataPath, $this->directory.'/linked.json');
        $process = $this->cliProcess(['xorgate:report:submit', $this->projectId(), $this->executionId(), '--input='.$this->directory.'/linked.json', '--file='.$report]);
        $process->run(); self::assertSame(2, $process->getExitCode());
        self::assertSame('invalid_input', $this->processJson($process)['error']['code']);
        self::assertSame(0, $this->countRows('report_import'));
        self::assertSame(0, $this->countRows('report_artifact'));
        self::assertSame(0, $this->countRows('messenger_messages'));
    }

    private function limits(int $pending = 100, int $retained = 1000, int $upload = 8388608, int $storage = 536870912): IntakeLimits
    {
        return new IntakeLimits($this->connection, $this->store, $pending, $retained, 100, $upload, $storage, 60, 120);
    }

    private function service(IntakeLimits $limits, ?MessageBusInterface $bus = null): ReportIntake
    {
        return new ReportIntake($this->connection, self::getContainer()->get(ProjectRegistry::class), $this->store,
            $bus ?? self::getContainer()->get(MessageBusInterface::class), $limits, self::getContainer()->get(JUnitAdapter::class), new NullLogger());
    }

    private function metadata(): array { return ['request_id' => self::id(), 'source' => ['tested_revision' => 'fixture-source-revision']]; }
    private function submit(string $bytes, ?array $metadata = null, ?ReportIntake $intake = null): array
    {
        return ($intake ?? $this->intake)->submitBase64($this->projectId(), $this->executionId(), $metadata ?? $this->metadata(), base64_encode($bytes));
    }
    private function operation(array $operation): array { return $this->intake->getOperation($this->projectId(), $this->executionId(), $operation['operation_id']); }
    private function projectId(): string { return $this->execution['project_id']; }
    private function executionId(): string { return $this->execution['execution_id']; }
    private static function id(): string { return Uuid::v7()->toRfc4122(); }
    private static function twoCases(): string { return '<testsuite name="synthetic-transaction-boundary"><testcase name="same"/><testcase name="same"><failure message="controlled"/></testcase></testsuite>'; }
    private function stream(string $content) { $stream = fopen('php://temp', 'w+b'); fwrite($stream, $content); rewind($stream); return $stream; }
    private function original(array $operation): string
    {
        $stream = $this->intake->openArtifact($this->projectId(), $this->executionId(), $operation['artifact']['artifact_id']);
        try { return stream_get_contents($stream); } finally { fclose($stream); }
    }
    private function blobPath(array $operation): string
    {
        $stream = $this->store->open($operation['artifact']['sha256'], $operation['artifact']['bytes']);
        try { return stream_get_meta_data($stream)['uri']; } finally { fclose($stream); }
    }
    private function countRows(string $table): int { return (int) $this->connection->fetchOne('SELECT COUNT(*) FROM '.$table); }
    private function resetReports(): void { foreach (['messenger_messages', 'report_case', 'report_suite', 'report_import', 'report_artifact'] as $table) { $this->connection->executeStatement('DELETE FROM '.$table); } }
    private function resetRecords(): void { $this->resetReports(); foreach (['registry_request', 'execution_record', 'managed_project'] as $table) { $this->connection->executeStatement('DELETE FROM '.$table); } }
    private function consumeOne(): void
    {
        $tester = new CommandTester((new Application(self::$kernel))->find('messenger:consume'));
        $tester->execute(['receivers' => ['async'], '--limit' => 1, '--time-limit' => 5, '--sleep' => 0.01], ['interactive' => false]);
        self::assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
    }
    private function cliProcess(array $arguments, ?string $input = null, array $environment = []): Process
    {
        $process = new Process([PHP_BINARY, 'bin/console', ...$arguments], self::$kernel->getProjectDir(), array_merge([
            'APP_ENV' => 'test', 'SYMFONY_DOTENV_VARS' => false, 'XORGATE_ARTIFACT_DIR' => $this->directory], $environment), $input);
        $process->setTimeout(15);
        return $process;
    }
    private function processJson(Process $process): array
    {
        $output = $process->getOutput();
        self::assertJson($output, 'Child CLI exit '.($process->getExitCode() ?? 'unknown').'; stdout: '.substr($output, 0, 1000).'; stderr: '.substr($process->getErrorOutput(), 0, 1000));
        return json_decode($output, true, 512, JSON_THROW_ON_ERROR);
    }
    private function removeDirectory(string $path): void
    {
        foreach (new \FilesystemIterator($path, \FilesystemIterator::SKIP_DOTS) as $entry) {
            $entry->isDir() && !$entry->isLink() ? $this->removeDirectory($entry->getPathname()) : unlink($entry->getPathname());
        }
        rmdir($path);
    }
}
