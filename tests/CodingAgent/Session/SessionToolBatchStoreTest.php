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

    private function createStore(HatfieldSessionStore $hatfield, ?AgentChildRunDirectory $directory = null): SessionToolBatchStore
    {
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
