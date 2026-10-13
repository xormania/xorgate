<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Command\DispatchFoundationProbeCommand;
use App\Exception\ProbeCapacityException;
use App\Mcp\FoundationTools;
use App\Message\RunFoundationProbe;
use App\Service\FoundationProbe;
use App\Service\FoundationStatus;
use App\Service\FoundationProbeLimits;
use App\Service\ArtifactStorage;
use Doctrine\DBAL\Connection;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Transport\Sender\SenderInterface;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Process\Process;

/** Uses the migrated, isolated PostgreSQL test database and actual Doctrine transport. */
final class FoundationProbeTest extends KernelTestCase
{
    private string $artifactDirectory;
    private Connection $connection;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artifactDirectory = sys_get_temp_dir().'/xorgate-test-artifacts-'.bin2hex(random_bytes(8));
        $_ENV['XORGATE_ARTIFACT_DIR'] = $_SERVER['XORGATE_ARTIFACT_DIR'] = $this->artifactDirectory;
        self::bootKernel();
        $this->connection = self::getContainer()->get(Connection::class);
        self::assertSame('test', self::$kernel->getEnvironment());
        self::assertMatchesRegularExpression('/_test[^\/]*$/', $this->connection->getDatabase(), 'Integration fixtures require an isolated test database.');
        $this->connection->executeStatement('DELETE FROM messenger_messages');
        $this->connection->executeStatement('DELETE FROM foundation_probe');
    }

    protected function tearDown(): void
    {
        self::ensureKernelShutdown();
        if (is_dir($this->artifactDirectory)) {
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->artifactDirectory, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($iterator as $entry) {
                $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
            }
            rmdir($this->artifactDirectory);
        }
        parent::tearDown();
    }

    public function testActualWorkerCompletesPersistedProbeAndRedeliveryPreservesEvidence(): void
    {
        $probes = self::getContainer()->get(FoundationProbe::class);
        $id = Uuid::v7()->toRfc4122();
        $queued = $probes->dispatch($id);

        self::assertSame('queued', $queued['status']);
        self::assertNull($queued['artifact']);
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM messenger_messages WHERE queue_name = ?', ['async']));
        self::assertSame($queued, $probes->dispatch($id));
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM messenger_messages WHERE queue_name = ?', ['async']));

        $this->consumeOne();
        $completed = $probes->get($id);
        self::assertSame('completed', $completed['status']);
        self::assertTrue($completed['artifact']['available']);
        self::assertNotNull($completed['completed_at']);
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM messenger_messages WHERE queue_name = ?', ['async']));

        $path = $this->artifactDirectory.'/'.$completed['artifact']['path'];
        $contents = file_get_contents($path);
        self::assertSame($completed['artifact']['sha256'], hash('sha256', $contents));
        self::assertSame($completed['artifact']['bytes'], strlen($contents));
        $artifact = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame($id, $artifact['probe_id']);
        self::assertSame($id, $artifact['correlation_id']);
        self::assertSame('consumed', $artifact['checks']['queue']);

        // Simulate transport redelivery, without bypassing the configured Messenger handler.
        $transport = self::getContainer()->get('messenger.transport.async');
        self::assertInstanceOf(SenderInterface::class, $transport);
        $transport->send(new Envelope(new RunFoundationProbe($id)));
        $this->consumeOne();
        self::assertSame($completed, $probes->get($id));
        self::assertSame($contents, file_get_contents($path));

        // A fresh kernel sees the same durable record and evidence within this test process.
        self::ensureKernelShutdown();
        self::bootKernel();
        self::assertSame($completed, self::getContainer()->get(FoundationProbe::class)->get($id));

        unlink($path);
        $missing = self::getContainer()->get(FoundationProbe::class)->get($id);
        self::assertSame('completed', $missing['status']);
        self::assertFalse($missing['artifact']['available']);
    }

    public function testDispatchRollsBackRecordWhenQueueCannotAcceptMessage(): void
    {
        $probes = self::getContainer()->get(FoundationProbe::class);
        $id = Uuid::v7()->toRfc4122();
        $this->connection->executeStatement('ALTER TABLE messenger_messages RENAME TO messenger_messages_unavailable');

        try {
            $probes->dispatch($id);
            self::fail('Dispatch must fail if durable queue storage is unavailable.');
        } catch (\RuntimeException $exception) {
            self::assertSame('Foundation probe could not be queued. Verify database and queue readiness.', $exception->getMessage());
            self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM foundation_probe WHERE id = ?', [$id]));
        } finally {
            $this->connection->executeStatement('ALTER TABLE messenger_messages_unavailable RENAME TO messenger_messages');
        }
    }

    public function testFailureKeepsProbeInspectableAndRedeliveryRecovers(): void
    {
        $probes = self::getContainer()->get(FoundationProbe::class);
        $queued = $probes->dispatch();
        mkdir($this->artifactDirectory, 0700, true);
        // A file at the directory location fails reliably even when tests run as root.
        file_put_contents($this->artifactDirectory.'/foundation', 'blocked');

        try {
            $probes->complete($queued['probe_id']);
            self::fail('The blocked artifact location must fail the probe.');
        } catch (\RuntimeException) {
            $failed = $probes->get($queued['probe_id']);
            self::assertSame('failed', $failed['status']);
            self::assertSame('probe_execution_failed', $failed['error']);
            self::assertNull($failed['artifact']);
        }

        unlink($this->artifactDirectory.'/foundation');
        $this->consumeOne();
        $completed = $probes->get($queued['probe_id']);
        self::assertSame('completed', $completed['status']);
        self::assertNull($completed['error']);
        self::assertTrue($completed['artifact']['available']);
    }

    public function testReadinessReportsUnavailableArtifactStorageWithoutLeakingPaths(): void
    {
        self::assertTrue(self::getContainer()->get(FoundationStatus::class)->snapshot()['ready']);
        rmdir($this->artifactDirectory);
        file_put_contents($this->artifactDirectory, 'blocked');

        try {
            $snapshot = self::getContainer()->get(FoundationStatus::class)->snapshot();
            self::assertFalse($snapshot['ready']);
            self::assertSame('unavailable', $snapshot['checks']['artifacts']['status']);
            self::assertSame('ready', $snapshot['checks']['database']['status']);
            self::assertSame('ready', $snapshot['checks']['queue']['status']);
            self::assertStringNotContainsString($this->artifactDirectory, json_encode($snapshot, JSON_THROW_ON_ERROR));
        } finally {
            unlink($this->artifactDirectory);
        }
    }

    public function testProbeIdentifiersCannotBecomeArtifactPaths(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        self::getContainer()->get(FoundationProbe::class)->dispatch('../../outside');
    }

    public function testAdmissionLimitsRejectWithoutWritesAndExistingIdsStillWork(): void
    {
        foreach (['pending_probe_limit', 'retained_probe_limit', 'probe_rate_limit'] as $reason) {
            $this->connection->executeStatement('DELETE FROM messenger_messages');
            $this->connection->executeStatement('DELETE FROM foundation_probe');
            $limits = $this->limits('probe_rate_limit' === $reason ? 2 : 1, 'retained_probe_limit' === $reason ? 1 : 2, 'probe_rate_limit' === $reason ? 1 : 2);
            $probes = $this->probes($limits);
            $first = $probes->dispatch();
            if ('pending_probe_limit' !== $reason) {
                $this->consumeOne();
                $first = $probes->get($first['probe_id']);
            }
            $messages = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM messenger_messages');
            try {
                $probes->dispatch();
                self::fail('New work must be refused at '.$reason);
            } catch (ProbeCapacityException $exception) {
                self::assertSame($reason, $exception->capacity['reason']);
            }
            self::assertSame($first, $probes->dispatch($first['probe_id']));
            self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM foundation_probe'));
            self::assertSame($messages, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM messenger_messages'));
        }
    }

    public function testConcurrentCliDispatchCannotOverrunPendingBudget(): void
    {
        $processes = [];
        $this->connection->beginTransaction();
        $this->connection->executeQuery('SELECT pg_advisory_xact_lock(?)', [FoundationProbeLimits::LOCK_KEY]);
        try {
            for ($i = 0; $i < 2; ++$i) {
                $process = new Process([PHP_BINARY, 'bin/console', 'xorgate:probe:dispatch', Uuid::v7()->toRfc4122()], self::$kernel->getProjectDir(), [
                    'APP_ENV' => 'test', 'XORGATE_ARTIFACT_DIR' => $this->artifactDirectory,
                    // Parent Dotenv metadata must not mark these explicit child overrides as reloadable defaults.
                    'SYMFONY_DOTENV_VARS' => false,
                    'XORGATE_PROBE_MAX_PENDING' => '1', 'XORGATE_PROBE_MAX_RETAINED' => '2', 'XORGATE_PROBE_MAX_PER_MINUTE' => '2',
                ]);
                $process->setTimeout(15);
                $process->start();
                $processes[] = $process;
            }
            usleep(100_000);
        } finally {
            $this->connection->commit();
        }

        $codes = [];
        foreach ($processes as $process) {
            $codes[] = $process->wait();
            $result = json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);
            if (0 !== $process->getExitCode()) {
                self::assertFalse($result['accepted']);
                self::assertSame('pending_probe_limit', $result['error'], $process->getErrorOutput());
            }
        }
        sort($codes);
        self::assertSame([0, 1], $codes);
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM foundation_probe'));
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM messenger_messages'));
    }

    public function testCapacityResponseAgreesAcrossMcpCliAndRuntimeQuery(): void
    {
        $limits = $this->limits(1, 2, 2);
        $probes = $this->probes($limits);
        $probes->dispatch();
        $status = new FoundationStatus($this->connection, self::getContainer()->get(ArtifactStorage::class), $limits, 'test');
        $tools = new FoundationTools($status, $probes);
        $rejected = $tools->dispatch();
        self::assertFalse($rejected['accepted']);
        self::assertSame('pending_probe_limit', $rejected['error']);
        self::assertSame($rejected['capacity'], $tools->runtimeStatus()['probe_capacity']);
        $cli = new CommandTester(new DispatchFoundationProbeCommand($probes));
        $cli->execute([], ['interactive' => false]);
        self::assertSame(1, $cli->getStatusCode());
        self::assertSame($rejected, json_decode($cli->getDisplay(), true, 512, JSON_THROW_ON_ERROR));
    }

    public function testRetentionExpiresThenPrunesCompletedWorkAndPreservesRecovery(): void
    {
        $probes = self::getContainer()->get(FoundationProbe::class);
        $limits = $this->limits(100, 1000, 30);
        $complete = $probes->dispatch();
        $this->consumeOne();
        $this->connection->executeStatement("UPDATE foundation_probe SET completed_at = CURRENT_TIMESTAMP - INTERVAL '90 seconds' WHERE id = ?", [$complete['probe_id']]);
        $pending = $probes->dispatch();
        $this->connection->executeStatement("UPDATE foundation_probe SET status = 'failed', created_at = CURRENT_TIMESTAMP - INTERVAL '1 day' WHERE id = ?", [$pending['probe_id']]);
        self::assertSame('queue_not_idle', $limits->cleanup()['deferred']);
        self::assertTrue($probes->get($complete['probe_id'])['artifact']['available']);
        // Model failed-transport recovery, which must also defer cleanup.
        $this->connection->executeStatement("UPDATE messenger_messages SET queue_name = 'failed'");
        self::assertSame('queue_not_idle', $limits->cleanup()['deferred']);
        $this->connection->executeStatement('DELETE FROM messenger_messages');
        $sweep = $limits->cleanup();
        self::assertSame(1, $sweep['expired']);
        self::assertSame(0, $sweep['pruned']);
        $expired = $probes->get($complete['probe_id']);
        self::assertFalse($expired['artifact']['available']);
        self::assertNotNull($expired['artifact']['expired_at']);
        self::assertFileDoesNotExist($this->artifactDirectory.'/'.$expired['artifact']['path']);
        self::assertSame($expired, $probes->dispatch($expired['probe_id']));
        $probes->complete($expired['probe_id']);
        self::assertSame($expired, $probes->get($expired['probe_id']));
        $this->connection->executeStatement("UPDATE foundation_probe SET completed_at = CURRENT_TIMESTAMP - INTERVAL '180 seconds' WHERE id = ?", [$expired['probe_id']]);
        self::assertSame(1, $limits->cleanup()['pruned']);
        self::assertFalse($this->connection->fetchOne('SELECT id FROM foundation_probe WHERE id = ?', [$expired['probe_id']]));
        self::assertSame('failed', $probes->get($pending['probe_id'])['status']);
    }

    public function testIdleWorkerAutomaticallySweepsExpiredProbes(): void
    {
        $probes = self::getContainer()->get(FoundationProbe::class);
        $old = $probes->dispatch();
        $this->consumeOne();
        $this->connection->executeStatement("UPDATE foundation_probe SET completed_at = CURRENT_TIMESTAMP - INTERVAL '8 days' WHERE id = ?", [$old['probe_id']]);
        self::ensureKernelShutdown();
        self::bootKernel();
        $probes = self::getContainer()->get(FoundationProbe::class);
        $tester = new CommandTester((new Application(self::$kernel))->find('messenger:consume'));
        $tester->execute(['receivers' => ['async'], '--time-limit' => 1, '--sleep' => 0.01], ['interactive' => false]);
        self::assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        self::assertNotNull($probes->get($old['probe_id'])['artifact']['expired_at']);
        self::assertFalse($probes->get($old['probe_id'])['artifact']['available']);
    }

    public function testRetentionRecoversWhenFileDeletionPrecedesDatabaseFailure(): void
    {
        $probes = self::getContainer()->get(FoundationProbe::class);
        $record = $probes->dispatch();
        $this->consumeOne();
        $this->connection->executeStatement("UPDATE foundation_probe SET completed_at = CURRENT_TIMESTAMP - INTERVAL '8 days' WHERE id = ?", [$record['probe_id']]);
        $limits = self::getContainer()->get(FoundationProbeLimits::class);
        $this->connection->executeStatement('ALTER TABLE foundation_probe ADD CONSTRAINT probe_expiration_failure CHECK (artifact_expired_at IS NULL)');
        try {
            $limits->cleanup();
            self::fail('The simulated metadata failure must roll back cleanup.');
        } catch (\Doctrine\DBAL\Exception) {
            self::assertNull($probes->get($record['probe_id'])['artifact']['expired_at']);
            self::assertFalse($probes->get($record['probe_id'])['artifact']['available']);
        } finally {
            $this->connection->executeStatement('ALTER TABLE foundation_probe DROP CONSTRAINT probe_expiration_failure');
        }
        self::assertSame(1, $limits->cleanup()['expired']);
        self::assertNotNull($probes->get($record['probe_id'])['artifact']['expired_at']);
    }

    public function testRetentionRefusesSymlinkEscapeWithoutDeletingUnrelatedFiles(): void
    {
        $probes = self::getContainer()->get(FoundationProbe::class);
        $record = $probes->dispatch();
        $this->consumeOne();
        $this->connection->executeStatement("UPDATE foundation_probe SET completed_at = CURRENT_TIMESTAMP - INTERVAL '8 days' WHERE id = ?", [$record['probe_id']]);
        $owned = $this->artifactDirectory.'/foundation';
        $outside = $this->artifactDirectory.'/unrelated';
        rename($owned, $outside);
        symlink($outside, $owned);
        try {
            self::getContainer()->get(FoundationProbeLimits::class)->cleanup();
            self::fail('Retention must reject a substituted directory.');
        } catch (\RuntimeException $exception) {
            self::assertSame('Artifact retention location is unsafe.', $exception->getMessage());
            self::assertFileExists($outside.'/'.$record['probe_id'].'.json');
            self::assertNull($probes->get($record['probe_id'])['artifact']['expired_at']);
        } finally {
            unlink($owned);
            rename($outside, $owned);
        }
    }

    private function limits(int $pending, int $retained, int $rate): FoundationProbeLimits
    {
        return new FoundationProbeLimits($this->connection, self::getContainer()->get(ArtifactStorage::class), $pending, $retained, $rate, 60, 120);
    }

    private function probes(FoundationProbeLimits $limits): FoundationProbe
    {
        return new FoundationProbe($this->connection, self::getContainer()->get(MessageBusInterface::class), self::getContainer()->get(ArtifactStorage::class), new NullLogger(), $limits);
    }

    private function consumeOne(): void
    {
        $application = new Application(self::$kernel);
        $tester = new CommandTester($application->find('messenger:consume'));
        $tester->execute(['receivers' => ['async'], '--limit' => 1, '--time-limit' => 5, '--sleep' => 0.01], ['interactive' => false]);
        self::assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
    }
}
