<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Message\RunFoundationProbe;
use App\Service\FoundationProbe;
use App\Service\FoundationStatus;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\Sender\SenderInterface;
use Symfony\Component\Uid\Uuid;

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

    private function consumeOne(): void
    {
        $application = new Application(self::$kernel);
        $tester = new CommandTester($application->find('messenger:consume'));
        $tester->execute(['receivers' => ['async'], '--limit' => 1, '--time-limit' => 5, '--sleep' => 0.01], ['interactive' => false]);
        self::assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
    }
}
