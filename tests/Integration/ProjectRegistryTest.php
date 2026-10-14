<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Exception\RegistryConflictException;
use App\Exception\RegistryCapacityException;
use App\Exception\RegistryNotFoundException;
use App\Service\ProjectRegistry;
use App\Service\RegistryLimits;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Process\Process;
use Symfony\Component\Uid\Uuid;

/** Exercises the migrated PostgreSQL registry, rather than an in-memory repository. */
final class ProjectRegistryTest extends KernelTestCase
{
    private Connection $connection;
    private ProjectRegistry $registry;

    protected function setUp(): void
    {
        parent::setUp();
        self::bootKernel();
        self::assertSame('test', self::$kernel->getEnvironment());
        $this->connection = self::getContainer()->get(Connection::class);
        self::assertMatchesRegularExpression('/_test[^\/]*$/', $this->connection->getDatabase(), 'Registry fixtures require an isolated test database.');
        $this->connection->executeStatement('DELETE FROM registry_request');
        $this->connection->executeStatement('DELETE FROM execution_record');
        $this->connection->executeStatement('DELETE FROM managed_project');
        $this->registry = self::getContainer()->get(ProjectRegistry::class);
    }

    public function testDifferentRuntimeProjectsRemainVisibleToAFreshKernel(): void
    {
        $python = $this->registry->register($this->projectInput('Python service', ['Python 3.13']));
        $mixed = $this->registry->register($this->projectInput('Mixed project', ['Rust', 'TypeScript', 'Python']));
        self::assertNotSame($python['project_id'], $mixed['project_id']);
        self::assertSame(1, $mixed['schema_version']);
        self::assertSame(['Rust', 'TypeScript', 'Python'], $mixed['runtimes']);
        self::assertNotEmpty($mixed['created_at']);

        self::ensureKernelShutdown();
        self::bootKernel();
        $registry = self::getContainer()->get(ProjectRegistry::class);
        self::assertSame($python, $registry->get($python['project_id']));
        self::assertSame($mixed, $registry->get($mixed['project_id']));
        $ids = array_column($registry->list()['items'], 'project_id');
        self::assertContains($python['project_id'], $ids);
        self::assertContains($mixed['project_id'], $ids);
    }

    public function testRegistrationReplayPreservesItsOriginalResponseAfterAnEdit(): void
    {
        $input = $this->projectInput('Original name', ['Go']);
        $original = $this->registry->register($input);
        self::assertSame($original, $this->registry->register($input));
        $edit = $this->projectInput('Renamed project', ['Go', 'TypeScript']);
        $edit['repository_url'] = 'https://example.test/new/location.git';
        $edit['expected_version'] = $original['version'];
        $edited = $this->registry->update($original['project_id'], $edit);
        self::assertSame($original['project_id'], $edited['project_id']);
        self::assertSame(2, $edited['version']);
        self::assertSame($original['created_at'], $edited['created_at']);
        self::assertSame($edited, $this->registry->update($original['project_id'], $edit));
        self::assertSame($original, $this->registry->register($input));
        self::assertSame($edited, $this->registry->get($original['project_id']));
        self::assertCount(1, $this->registry->list()['items']);

        $input['name'] = 'A different request';
        $this->expectException(RegistryConflictException::class);
        $this->registry->register($input);
    }

    public function testStaleEditCannotOverwriteAProject(): void
    {
        $project = $this->registry->register($this->projectInput());
        $edit = $this->projectInput('First edit', ['Rust']);
        $edit['expected_version'] = 1;
        $updated = $this->registry->update($project['project_id'], $edit);
        $stale = $this->projectInput('Stale edit', ['Java']);
        $stale['expected_version'] = 1;
        try {
            $this->registry->update($project['project_id'], $stale);
            self::fail('A stale version must not overwrite newer metadata.');
        } catch (RegistryConflictException) {
            self::assertSame($updated, $this->registry->get($project['project_id']));
        }
    }

    public function testProviderAttemptsAndPrRevisionsRemainSeparateImmutableSnapshots(): void
    {
        $projectInput = $this->projectInput('Mixed runtime', ['Rust', 'TypeScript']);
        $projectInput['repository_identity'] = 'forge:repository:42';
        $project = $this->registry->register($projectInput);
        $firstInput = $this->executionInput(1);
        $first = $this->registry->recordExecution($project['project_id'], $firstInput);
        $second = $this->registry->recordExecution($project['project_id'], $this->executionInput(2));
        self::assertNotSame($first['execution_id'], $second['execution_id']);
        self::assertSame($first['source']['tested_revision'], $second['source']['tested_revision']);
        self::assertSame(1, $first['native_identity']['run_attempt']);
        self::assertSame(2, $second['native_identity']['run_attempt']);
        self::assertSame('job:linux', $first['native_identity']['job_id']);
        self::assertSame('shard:2', $first['native_identity']['shard']);
        self::assertSame('tested-merge-revision', $first['source']['tested_revision']);
        self::assertSame('pr-head-revision', $first['source']['pr_head_revision']);
        self::assertSame('pr-base-revision', $first['source']['pr_base_revision']);
        self::assertSame('pr-merge-revision', $first['source']['pr_merge_revision']);
        self::assertSame('forge:repository:42', $first['source']['repository_identity']);
        self::assertSame($project['repository_url'], $first['source']['repository_url']);
        self::assertSame($first, $this->registry->recordExecution($project['project_id'], $firstInput));

        $edit = $this->projectInput('Moved project', ['Python']);
        $edit['repository_identity'] = 'forge:repository:99';
        $edit['repository_url'] = 'https://example.test/moved.git';
        $edit['expected_version'] = 1;
        $this->registry->update($project['project_id'], $edit);
        self::assertSame($first, $this->registry->getExecution($project['project_id'], $first['execution_id']));
        self::assertSame($first, $this->registry->recordExecution($project['project_id'], $firstInput));
        self::assertSame($second, $this->registry->getExecution($project['project_id'], $second['execution_id']));
    }

    public function testExecutionReplayCannotRewriteTheTestedRevision(): void
    {
        $project = $this->registry->register($this->projectInput());
        $input = $this->executionInput(1);
        $original = $this->registry->recordExecution($project['project_id'], $input);
        $input['source']['tested_revision'] = 'another-tested-revision';
        try {
            $this->registry->recordExecution($project['project_id'], $input);
            self::fail('A replay with changed provenance must conflict.');
        } catch (RegistryConflictException) {
            self::assertSame($original, $this->registry->getExecution($project['project_id'], $original['execution_id']));
            self::assertCount(1, $this->registry->listExecutions($project['project_id'])['items']);
        }
    }

    public function testExecutionLookupCannotCrossTheProjectBoundary(): void
    {
        $first = $this->registry->register($this->projectInput('First'));
        $second = $this->registry->register($this->projectInput('Second'));
        $execution = $this->registry->recordExecution($first['project_id'], $this->executionInput(1));
        self::assertSame([], $this->registry->listExecutions($second['project_id'])['items']);
        $this->expectException(RegistryNotFoundException::class);
        $this->registry->getExecution($second['project_id'], $execution['execution_id']);
    }

    public function testExplicitForeignRepositoryIdentityCannotAttachToAProject(): void
    {
        $input = $this->projectInput();
        $input['repository_identity'] = 'forge:repository:42';
        $project = $this->registry->register($input);
        $execution = $this->executionInput(1);
        $execution['source']['repository_identity'] = 'forge:repository:43';
        try {
            $this->registry->recordExecution($project['project_id'], $execution);
            self::fail('An explicitly foreign repository identity must be rejected.');
        } catch (RegistryConflictException) {
            self::assertSame([], $this->registry->listExecutions($project['project_id'])['items']);
        }
    }

    public function testEvidenceContextMustMatchTheImmutableExecutionSource(): void
    {
        $project = $this->registry->register($this->projectInput());
        $execution = $this->registry->recordExecution($project['project_id'], $this->executionInput(1));
        self::assertSame($execution, $this->registry->assertExecutionContext($project['project_id'], $execution['execution_id'], [
            'tested_revision' => $execution['source']['tested_revision'],
            'workspace_fingerprint' => $execution['source']['workspace_fingerprint'],
        ]));
        try {
            $this->registry->assertExecutionContext($project['project_id'], $execution['execution_id'], ['tested_revision' => 'different-revision']);
            self::fail('Evidence from a different tested revision must conflict.');
        } catch (RegistryConflictException) {
            self::assertSame($execution, $this->registry->getExecution($project['project_id'], $execution['execution_id']));
        }
    }

    public function testEvidenceContextRequiresAtLeastOneKnownSourceIdentifier(): void
    {
        $project = $this->registry->register($this->projectInput());
        $unknown = $this->registry->recordExecution($project['project_id'], ['request_id' => $this->requestId()]);
        $requests = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM registry_request');
        foreach ([['tested_revision' => null], ['tested_revision' => '   '], ['workspace_fingerprint' => '', 'pr_merge_revision' => null]] as $context) {
            try {
                $this->registry->assertExecutionContext($project['project_id'], $unknown['execution_id'], $context);
                self::fail('Unknown-only evidence context must not authorize attachment.');
            } catch (\InvalidArgumentException) {
                self::assertSame($unknown, $this->registry->getExecution($project['project_id'], $unknown['execution_id']));
            }
        }
        self::assertSame($requests, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM registry_request'));
        self::assertCount(1, $this->registry->listExecutions($project['project_id'])['items']);

        // An opaque, nonempty "0" is a known revision even though PHP treats it as falsey.
        $known = $this->registry->recordExecution($project['project_id'], [
            'request_id' => $this->requestId(), 'source' => ['tested_revision' => '0'],
        ]);
        $requests = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM registry_request');
        self::assertSame($known, $this->registry->assertExecutionContext($project['project_id'], $known['execution_id'], [
            'tested_revision' => '0', 'workspace_fingerprint' => null,
        ]));
        self::assertSame($requests, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM registry_request'));
        self::assertCount(2, $this->registry->listExecutions($project['project_id'])['items']);
    }

    public function testUnknownSourceAndEnvironmentAreExplicitAndNotFabricated(): void
    {
        $project = $this->registry->register(['request_id' => $this->requestId(), 'name' => 'Local project']);
        self::assertNull($project['repository_url']);
        self::assertNull($project['repository_identity']);
        self::assertSame([], $project['runtimes']);
        $execution = $this->registry->recordExecution($project['project_id'], ['request_id' => $this->requestId()]);
        foreach (['repository_identity', 'repository_url', 'tested_revision', 'workspace_fingerprint', 'pr_head_revision', 'pr_base_revision', 'pr_merge_revision'] as $field) {
            self::assertArrayHasKey($field, $execution['source']);
            self::assertNull($execution['source'][$field]);
        }
        self::assertNull($execution['environment']['name']);
        self::assertSame([], $execution['environment']['runtimes']);
        self::assertNull($execution['environment']['configuration_ref']);
        self::assertNull($execution['producer']['name']);
        self::assertNull($execution['producer']['version']);
        foreach (['provider', 'run_id', 'run_attempt', 'job_id', 'shard'] as $field) {
            self::assertArrayHasKey($field, $execution['native_identity']);
            self::assertNull($execution['native_identity'][$field]);
        }
        self::assertNull($execution['observed_at']);
        self::assertNotEmpty($execution['created_at']);
    }

    public function testProjectAndExecutionListsUseBoundedKeysetPages(): void
    {
        $projects = [];
        for ($i = 0; $i < 3; ++$i) {
            $projects[] = $this->registry->register($this->projectInput('Project '.$i));
        }
        $page = $this->registry->list(2);
        self::assertSame(2, $page['limit']);
        self::assertCount(2, $page['items']);
        self::assertNotNull($page['next_cursor']);
        $next = $this->registry->list(2, $page['next_cursor']);
        self::assertCount(1, $next['items']);
        self::assertNull($next['next_cursor']);
        $ids = array_column([...$page['items'], ...$next['items']], 'project_id');
        self::assertCount(3, array_unique($ids));
        foreach ($projects as $project) {
            self::assertContains($project['project_id'], $ids);
        }

        $projectId = $projects[0]['project_id'];
        $executions = [];
        for ($attempt = 1; $attempt <= 3; ++$attempt) {
            $executions[] = $this->registry->recordExecution($projectId, $this->executionInput($attempt));
        }
        $page = $this->registry->listExecutions($projectId, 2);
        self::assertCount(2, $page['items']);
        $next = $this->registry->listExecutions($projectId, 2, $page['next_cursor']);
        self::assertCount(1, $next['items']);
        self::assertNull($next['next_cursor']);
        self::assertCount(3, array_unique(array_column([...$page['items'], ...$next['items']], 'execution_id')));
    }

    public function testMalformedIdentifiersAndUnboundedInputsFailBeforeWriting(): void
    {
        $invalidCalls = [
            fn () => $this->registry->get('../../outside'),
            fn () => $this->registry->list(0),
            fn () => $this->registry->list(101),
            fn () => $this->registry->list(25, 'not-a-uuid'),
            fn () => $this->registry->register(['name' => 'No replay key']),
            fn () => $this->registry->register(['request_id' => 'invalid', 'name' => 'Bad replay key']),
            fn () => $this->registry->register(['request_id' => $this->requestId(), 'name' => str_repeat('x', 121)]),
            fn () => $this->registry->register(['request_id' => $this->requestId(), 'name' => 'Too many runtimes', 'runtimes' => array_fill(0, 9, 'Runtime')]),
            fn () => $this->registry->register(['request_id' => $this->requestId(), 'name' => 'Unexpected field', 'invented' => true]),
        ];
        foreach ($invalidCalls as $call) {
            try {
                $call();
                self::fail('Invalid registry input must fail validation.');
            } catch (\InvalidArgumentException $exception) {
                self::assertNotSame('', $exception->getMessage());
                self::assertStringNotContainsString('SQL', $exception->getMessage());
            }
        }
        self::assertSame([], $this->registry->list()['items']);
    }

    public function testMalformedExecutionMetadataFailsWithoutCreatingHistory(): void
    {
        $project = $this->registry->register($this->projectInput());
        $invalidInputs = [
            ['request_id' => $this->requestId(), 'source' => ['tested_revision' => str_repeat('r', 257)]],
            ['request_id' => $this->requestId(), 'source' => ['invented_revision' => 'fake']],
            ['request_id' => $this->requestId(), 'native_identity' => ['run_attempt' => 0]],
            ['request_id' => $this->requestId(), 'native_identity' => ['run_attempt' => '2']],
            ['request_id' => $this->requestId(), 'observed_at' => '2026-10-13T12:34:56'],
        ];
        foreach ($invalidInputs as $input) {
            try {
                $this->registry->recordExecution($project['project_id'], $input);
                self::fail('Invalid execution metadata must fail validation.');
            } catch (\InvalidArgumentException $exception) {
                self::assertNotSame('', $exception->getMessage());
            }
        }
        self::assertSame([], $this->registry->listExecutions($project['project_id'])['items']);
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM registry_request'));
    }

    public function testAdmissionLimitsRejectNewWritesAndKeepExistingReplaysUsable(): void
    {
        foreach (['project_limit', 'execution_limit', 'registry_request_limit', 'registry_rate_limit'] as $reason) {
            $this->connection->executeStatement('DELETE FROM registry_request');
            $this->connection->executeStatement('DELETE FROM execution_record');
            $this->connection->executeStatement('DELETE FROM managed_project');
            $limits = new RegistryLimits(
                $this->connection,
                'project_limit' === $reason ? 1 : 100,
                'execution_limit' === $reason ? 1 : 100,
                'registry_request_limit' === $reason ? 1 : 100,
                'registry_rate_limit' === $reason ? 1 : 100,
            );
            $registry = new ProjectRegistry($this->connection, $limits);
            $projectInput = $this->projectInput();
            $project = $registry->register($projectInput);
            $executionInput = $this->executionInput(1);
            $execution = 'execution_limit' === $reason ? $registry->recordExecution($project['project_id'], $executionInput) : null;
            $before = $limits->snapshot();
            try {
                if ('execution_limit' === $reason) {
                    $registry->recordExecution($project['project_id'], $this->executionInput(2));
                } else {
                    $registry->register($this->projectInput('Rejected project'));
                }
                self::fail('New writes must be rejected at '.$reason);
            } catch (RegistryCapacityException $exception) {
                self::assertSame($reason, $exception->capacity['reason']);
                self::assertSame('capacity', $exception->response()['error']['code']);
            }
            self::assertSame($before, $limits->snapshot());
            self::assertSame($project, $registry->register($projectInput));
            if (null !== $execution) {
                self::assertSame($execution, $registry->recordExecution($project['project_id'], $executionInput));
            }
        }
    }

    public function testConcurrentCliRegistrationCannotOverrunTheProjectBudget(): void
    {
        $results = $this->concurrentRegistrations([$this->projectInput('Concurrent 0'), $this->projectInput('Concurrent 1')]);
        $codes = [];
        foreach ($results as [$code, $result]) {
            $codes[] = $code;
            if (0 !== $code) {
                self::assertFalse($result['accepted']);
                self::assertSame('project_limit', $result['capacity']['reason']);
            }
        }
        sort($codes);
        self::assertSame([0, 1], $codes);
        self::assertCount(1, $this->registry->list()['items']);
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM registry_request'));
    }

    public function testConcurrentCliReplaysReturnOneIdentityAtCapacity(): void
    {
        $input = $this->projectInput('Concurrent replay');
        $results = $this->concurrentRegistrations([$input, $input]);
        self::assertSame(0, $results[0][0]);
        self::assertSame(0, $results[1][0]);
        self::assertSame($results[0][1], $results[1][1]);
        self::assertCount(1, $this->registry->list()['items']);
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM registry_request'));
    }

    private function concurrentRegistrations(array $inputs): array
    {
        $processes = [];
        $this->connection->beginTransaction();
        $this->connection->executeQuery('SELECT pg_advisory_xact_lock(?)', [RegistryLimits::LOCK_KEY]);
        try {
            foreach ($inputs as $input) {
                $process = new Process([PHP_BINARY, 'bin/console', 'xorgate:project:register', '--input=-'], self::$kernel->getProjectDir(), [
                    'APP_ENV' => 'test',
                    // Explicit subprocess limits must not be reloaded as parent Dotenv defaults.
                    'SYMFONY_DOTENV_VARS' => false,
                    'XORGATE_REGISTRY_MAX_PROJECTS' => '1', 'XORGATE_REGISTRY_MAX_EXECUTIONS' => '100',
                    'XORGATE_REGISTRY_MAX_REQUESTS' => '100', 'XORGATE_REGISTRY_MAX_PER_MINUTE' => '100',
                ]);
                $process->setInput(json_encode($input, JSON_THROW_ON_ERROR));
                $process->setTimeout(15);
                $process->start();
                $processes[] = $process;
            }
            usleep(100_000);
        } finally {
            $this->connection->commit();
        }
        $results = [];
        foreach ($processes as $process) {
            $code = $process->wait();
            try {
                $result = json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                self::fail('Registration CLI did not return JSON. '.self::childDiagnostic($process));
            }
            self::assertIsArray($result, self::childDiagnostic($process));
            $results[] = [$code, $result];
        }

        return $results;
    }

    private static function childDiagnostic(Process $process): string
    {
        $diagnostic = 'Exit '.$process->getExitCode().'; stdout: '.$process->getOutput().'; stderr: '.$process->getErrorOutput();
        foreach (['APP_SECRET', 'XORGATE_MCP_TOKEN', 'DATABASE_URL'] as $name) {
            $value = $_SERVER[$name] ?? $_ENV[$name] ?? getenv($name);
            if (is_string($value) && '' !== $value) {
                $diagnostic = str_replace($value, '<redacted>', $diagnostic);
                if ('DATABASE_URL' === $name) {
                    $password = parse_url($value, PHP_URL_PASS);
                    if (is_string($password) && '' !== $password) {
                        $diagnostic = str_replace($password, '<redacted>', $diagnostic);
                    }
                }
            }
        }
        $diagnostic = preg_replace('/"password"\s*:\s*"[^"]*"/', '"password":"<redacted>"', $diagnostic) ?? 'Child diagnostic unavailable.';

        return substr($diagnostic, 0, 4096);
    }

    private function projectInput(string $name = 'Project', array $runtimes = ['Rust']): array
    {
        return [
            'request_id' => $this->requestId(),
            'name' => $name,
            'repository_url' => 'https://example.test/team/project.git',
            'runtimes' => $runtimes,
        ];
    }

    private function executionInput(int $attempt): array
    {
        return [
            'request_id' => $this->requestId(),
            'source' => [
                'tested_revision' => 'tested-merge-revision',
                'workspace_fingerprint' => 'workspace:sha256:example',
                'pr_head_revision' => 'pr-head-revision',
                'pr_base_revision' => 'pr-base-revision',
                'pr_merge_revision' => 'pr-merge-revision',
            ],
            'environment' => ['name' => 'linux-ci', 'runtimes' => ['Rust', 'TypeScript'], 'configuration_ref' => 'ci/config-v1'],
            'producer' => ['name' => 'registry-integration-test', 'version' => '1'],
            'native_identity' => ['provider' => 'example-ci', 'run_id' => 'run:123', 'run_attempt' => $attempt, 'job_id' => 'job:linux', 'shard' => 'shard:2'],
            'observed_at' => '2026-10-13T12:34:56+00:00',
        ];
    }

    private function requestId(): string
    {
        return Uuid::v7()->toRfc4122();
    }
}
