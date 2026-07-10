<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Session;

use Doctrine\ORM\EntityManagerInterface;
use Ineersa\AgentCore\Contract\Tool\ToolBatchStoreMutation;
use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactEntryDTO;
use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactKindEnum;
use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactPathResolver;
use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactPathsDTO;
use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactRegistry;
use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactStatusEnum;
use Ineersa\CodingAgent\Agent\Artifact\AgentChildRunDirectory;
use Ineersa\CodingAgent\Agent\Artifact\ChildAwareToolBatchRunStoragePaths;
use Ineersa\CodingAgent\Config\AppConfig;
use Ineersa\CodingAgent\Config\LoggingConfig;
use Ineersa\CodingAgent\Config\TuiConfig;
use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Ineersa\CodingAgent\Session\SessionToolBatchStore;
use Ineersa\CodingAgent\Session\SessionToolBatchStoreException;
use Ineersa\CodingAgent\Tests\Session\Support\ParentSessionToolBatchRunStoragePaths;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;
use Symfony\Component\Process\Process;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Serializer\NameConverter\CamelCaseToSnakeCaseNameConverter;
use Symfony\Component\Serializer\Normalizer\BackedEnumNormalizer;
use Symfony\Component\Serializer\Normalizer\DateTimeNormalizer;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;
use Symfony\Component\Serializer\Serializer;
use Symfony\Component\Validator\ValidatorBuilder;

final class SessionToolBatchStoreTest extends TestCase
{
    private string $projectDir = '';
    private SessionToolBatchStore $store;
    private HatfieldSessionStore $hatfieldSessionStore;

    protected function setUp(): void
    {
        parent::setUp();

        $this->projectDir = TestDirectoryIsolation::createOsTempDir('session-tool-batch');
        TestDirectoryIsolation::createHatfieldTree($this->projectDir, withSessions: true);

        $entityManager = $this->createStub(EntityManagerInterface::class);
        $appConfig = new AppConfig(
            tui: new TuiConfig(theme: 'default'),
            logging: new LoggingConfig(),
            cwd: $this->projectDir,
        );
        $this->hatfieldSessionStore = new HatfieldSessionStore($appConfig, $entityManager);

        $this->store = $this->createStore($this->hatfieldSessionStore);
    }

    protected function tearDown(): void
    {
        TestDirectoryIsolation::removeDirectory($this->projectDir);
        parent::tearDown();
    }

    public function testSaveLoadMutateDeleteIsolatesCompositeKeys(): void
    {
        $batchA = $this->emptyBatch(['c1']);
        $batchB = $this->emptyBatch(['c2']);

        $this->store->save('run-1', 1, 'step-a', $batchA);
        $this->store->save('run-1', 1, 'step-b', $batchB);

        $this->assertSame($batchA, $this->store->load('run-1', 1, 'step-a'));
        $this->assertSame($batchB, $this->store->load('run-1', 1, 'step-b'));

        $mutated = $this->store->mutate('run-1', 1, 'step-a', static function (?array $current): ToolBatchStoreMutation {
            $next = $current;
            $next['finalized'] = true;

            return new ToolBatchStoreMutation('ok', $next);
        });
        $this->assertSame('ok', $mutated);
        $loaded = $this->store->load('run-1', 1, 'step-a');
        $this->assertTrue($loaded['finalized']);

        $this->store->delete('run-1', 1, 'step-a');
        $this->assertNull($this->store->load('run-1', 1, 'step-a'));
        $this->assertNotNull($this->store->load('run-1', 1, 'step-b'));
    }

    public function testLoadRejectsMismatchedEmbeddedIdentity(): void
    {
        $runId = 'run-1';
        $turnNo = 1;
        $stepId = 'step-a';
        $dir = $this->hatfieldSessionStore->resolveSessionsBasePath().'/'.$runId.'/runtime/tool-batches';
        mkdir($dir, 0777, true);
        $filename = \sprintf('%d_%s.json', $turnNo, hash('sha256', $stepId));
        $envelope = [
            'run_id' => 'other-run',
            'turn_no' => $turnNo,
            'step_id' => $stepId,
            'batch_state' => $this->emptyBatch([]),
        ];
        file_put_contents($dir.'/'.$filename, json_encode($envelope, \JSON_THROW_ON_ERROR));

        $this->expectException(SessionToolBatchStoreException::class);
        $this->expectExceptionMessage('identity mismatch');
        $this->store->load($runId, $turnNo, $stepId);
    }

    public function testDeleteAllForRunRemovesOnlyThatRun(): void
    {
        $state = $this->emptyBatch([]);
        $this->store->save('run-1', 1, 's1', $state);
        $this->store->save('run-2', 1, 's1', $state);

        $this->store->deleteAllForRun('run-1');

        $this->assertNull($this->store->load('run-1', 1, 's1'));
        $this->assertNotNull($this->store->load('run-2', 1, 's1'));
    }

    public function testChildRunSnapshotsLiveUnderParentArtifactDirNotPseudoSession(): void
    {
        $parentRunId = '6';
        $childRunId = 'child-run-uuid';
        $artifactId = 'agent_test123';

        $parentDir = $this->hatfieldSessionStore->resolveSessionsBasePath().'/'.$parentRunId;
        mkdir($parentDir.'/artifacts/agents/'.$artifactId, 0777, true);

        $pathResolver = new AgentArtifactPathResolver($this->hatfieldSessionStore);
        $serializer = new Serializer(
            [new DateTimeNormalizer(), new BackedEnumNormalizer(), new ObjectNormalizer(nameConverter: new CamelCaseToSnakeCaseNameConverter())],
            [new JsonEncoder()],
        );
        $validator = (new ValidatorBuilder())->enableAttributeMapping()->getValidator();
        $registry = new AgentArtifactRegistry($pathResolver, $serializer, $validator, new LockFactory(new FlockStore()));

        $entry = new AgentArtifactEntryDTO(
            artifactId: $artifactId,
            parentRunId: $parentRunId,
            agentRunId: $childRunId,
            agentName: 'scout',
            kind: AgentArtifactKindEnum::Subagent,
            status: AgentArtifactStatusEnum::Running,
            paths: AgentArtifactPathsDTO::forArtifactId($artifactId),
            createdAt: new \DateTimeImmutable(),
        );

        $directory = new AgentChildRunDirectory($this->hatfieldSessionStore, $registry, new NullLogger());
        $directory->register($entry);

        $childStore = new SessionToolBatchStore(new ChildAwareToolBatchRunStoragePaths($this->hatfieldSessionStore, $directory, $pathResolver), new LockFactory(new FlockStore()), new NullLogger());
        $childStore->save($childRunId, 2, 'step-child', $this->emptyBatch(['c1']));

        $expectedDir = $parentDir.'/artifacts/agents/'.$artifactId.'/runtime/tool-batches';
        $this->assertDirectoryExists($expectedDir);
        $this->assertDirectoryDoesNotExist($this->hatfieldSessionStore->resolveSessionsBasePath().'/'.$childRunId);
    }

    public function testParallelWorkerProcessesMutateWithoutLosingResults(): void
    {
        $parallelProjectDir = TestDirectoryIsolation::createOsTempDir('session-tool-batch-parallel');
        TestDirectoryIsolation::createHatfieldTree($parallelProjectDir, withSessions: true);

        try {
            $parallelStore = $this->createStoreForProjectDir($parallelProjectDir);
            $parallelStore->save('run-par', 1, 'step-1', [
                'expected_order' => ['c1' => 0, 'c2' => 1],
                'call_data' => [],
                'pending_queue' => ['c1', 'c2'],
                'in_flight' => [],
                'result_data' => [],
                'finalized' => false,
                'max_parallelism' => 2,
            ]);

            $autoload = \dirname(__DIR__, 3).'/vendor/autoload.php';
            $script = \dirname(__DIR__, 3).'/tests/CodingAgent/Session/Support/session_tool_batch_mutate_worker.php';
            $this->assertFileExists($script);

            $gatePath = $parallelProjectDir.'/.mutate-gate';
            touch($gatePath);

            $env = [
                'HATFIELD_SESSIONS_BASE' => $parallelProjectDir.'/.hatfield/sessions',
                'HATFIELD_TOOL_BATCH_MUTATE_GATE' => $gatePath,
            ];

            $p1 = new Process(['php', $script, $autoload, 'c1'], env: $env);
            $p2 = new Process(['php', $script, $autoload, 'c2'], env: $env);
            $p1->setTimeout(10);
            $p2->setTimeout(10);

            $p1->start();
            $p2->start();

            $this->waitUntilWorkersReady($gatePath, 2, 10.0);

            $gate = fopen($gatePath, 'c+b');
            $this->assertNotFalse($gate);
            try {
                if (!flock($gate, \LOCK_EX)) {
                    $this->fail('Failed to release mutate gate for parallel workers.');
                }
            } finally {
                flock($gate, \LOCK_UN);
                fclose($gate);
            }

            try {
                $p1->wait();
                $p2->wait();
            } finally {
                $this->stopProcessIfRunning($p1);
                $this->stopProcessIfRunning($p2);
            }

            $this->assertTrue($p1->isSuccessful(), $p1->getErrorOutput().$p1->getOutput());
            $this->assertTrue($p2->isSuccessful(), $p2->getErrorOutput().$p2->getOutput());

            $final = $parallelStore->load('run-par', 1, 'step-1');
            $this->assertNotNull($final);
            $this->assertTrue($final['finalized']);
            $this->assertArrayHasKey('c1', $final['result_data']);
            $this->assertArrayHasKey('c2', $final['result_data']);
        } finally {
            TestDirectoryIsolation::removeDirectory($parallelProjectDir);
        }
    }

    private function waitUntilWorkersReady(string $gatePath, int $expectedReady, float $timeoutSeconds): void
    {
        $deadline = microtime(true) + $timeoutSeconds;
        while (microtime(true) < $deadline) {
            $ready = 0;
            $lines = @file($gatePath, \FILE_IGNORE_NEW_LINES);
            if (\is_array($lines)) {
                foreach ($lines as $line) {
                    if ('ready' === $line) {
                        ++$ready;
                    }
                }
            }

            if ($ready >= $expectedReady) {
                return;
            }
        }

        $this->fail(\sprintf('Timed out waiting for %d parallel workers to reach mutate gate.', $expectedReady));
    }

    private function stopProcessIfRunning(Process $process): void
    {
        if ($process->isRunning()) {
            $process->stop(0);
        }
    }

    private function createStore(HatfieldSessionStore $hatfield, ?AgentChildRunDirectory $directory = null): SessionToolBatchStore
    {
        return $this->createStoreForProjectDir($this->projectDir, $hatfield, $directory);
    }

    private function createStoreForProjectDir(string $projectDir, ?HatfieldSessionStore $hatfield = null, ?AgentChildRunDirectory $directory = null): SessionToolBatchStore
    {
        if (null === $hatfield) {
            $entityManager = $this->createStub(EntityManagerInterface::class);
            $appConfig = new AppConfig(
                tui: new TuiConfig(theme: 'default'),
                logging: new LoggingConfig(),
                cwd: $projectDir,
            );
            $hatfield = new HatfieldSessionStore($appConfig, $entityManager);
        }

        $pathResolver = new AgentArtifactPathResolver($hatfield);
        $serializer = new Serializer(
            [new DateTimeNormalizer(), new BackedEnumNormalizer(), new ObjectNormalizer(nameConverter: new CamelCaseToSnakeCaseNameConverter())],
            [new JsonEncoder()],
        );
        $validator = (new ValidatorBuilder())->enableAttributeMapping()->getValidator();
        $directory ??= new AgentChildRunDirectory(
            $hatfield,
            new AgentArtifactRegistry($pathResolver, $serializer, $validator, new LockFactory(new FlockStore())),
            new NullLogger(),
        );

        return new SessionToolBatchStore(
            new ParentSessionToolBatchRunStoragePaths($hatfield),
            new LockFactory(new FlockStore()),
            new NullLogger(),
        );
    }

    /**
     * @param list<string> $pending
     *
     * @return array<string, mixed>
     */
    private function emptyBatch(array $pending): array
    {
        $expected = [];
        foreach ($pending as $i => $id) {
            $expected[$id] = $i;
        }

        return [
            'expected_order' => $expected,
            'call_data' => [],
            'pending_queue' => $pending,
            'in_flight' => [],
            'result_data' => [],
            'finalized' => false,
            'max_parallelism' => 2,
        ];
    }
}
