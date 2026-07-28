<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\ObservationalMemory\Tests;

use Ineersa\AgentCore\Application\Tool\StackToolExecutionContextAccessor;
use Ineersa\AgentCore\Application\Tool\ToolContext;
use Ineersa\AgentCore\Contract\Hook\NullCancellationToken;
use Ineersa\CodingAgent\Extension\ExtensionToolHandlerAdapter;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;
use Ineersa\Hatfield\ExtensionApi\Agent\AgentRunnerInterface;
use Ineersa\Hatfield\ExtensionApi\Agent\ExtensionAgentJobHandlerInterface;
use Ineersa\Hatfield\ExtensionApi\Agent\ExtensionAgentJobRequestDTO;
use Ineersa\Hatfield\ExtensionApi\Command\CommandDefinitionDTO;
use Ineersa\Hatfield\ExtensionApi\Command\ExtensionCommandHandlerInterface;
use Ineersa\Hatfield\ExtensionApi\Compaction\BeforeCompactionHookInterface;
use Ineersa\Hatfield\ExtensionApi\Exec\ExecInterface;
use Ineersa\Hatfield\ExtensionApi\ExtensionApiInterface;
use Ineersa\Hatfield\ExtensionApi\Lifecycle\AfterTurnCommitHookInterface;
use Ineersa\Hatfield\ExtensionApi\Prompt\PromptContributorInterface;
use Ineersa\Hatfield\ExtensionApi\Session\SessionEventDTO;
use Ineersa\Hatfield\ExtensionApi\Session\SessionEventReaderInterface;
use Ineersa\Hatfield\ExtensionApi\Tool\ToolCallHookInterface;
use Ineersa\Hatfield\ExtensionApi\Tool\ToolCallRewriteHookInterface;
use Ineersa\Hatfield\ExtensionApi\Tool\ToolRegistrationDTO;
use Ineersa\Hatfield\ExtensionApi\Tool\ToolResultHookInterface;
use Ineersa\HatfieldExt\ObservationalMemory\Query\OmQueryService;
use Ineersa\HatfieldExt\ObservationalMemory\Runtime\OmSettings;
use Ineersa\HatfieldExt\ObservationalMemory\Storage\MemoryGenerationRepository;
use Ineersa\HatfieldExt\ObservationalMemory\Storage\ObservationRepository;
use Ineersa\HatfieldExt\ObservationalMemory\Tests\Support\OmDatabaseFactoryTestService;
use Ineersa\HatfieldExt\ObservationalMemory\Tool\RecallToolHandler;
use PHPUnit\Framework\Attributes\Test;

/**
 * Thesis: permanent recall tool resolves exact current-run observation/reflection ids via
 * contextual adapter + SessionEventReader; invalid/not-found and cross-session isolation hold.
 */
final class RecallToolHandlerTest extends IsolatedKernelTestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpDir = TestDirectoryIsolation::createProjectTempDir('om-recall');
    }

    protected function tearDown(): void
    {
        TestDirectoryIsolation::removeDirectory($this->tmpDir);
        parent::tearDown();
    }

    #[Test]
    public function contextualAdapterRecallsObservationAndReflectionWithExactEvents(): void
    {
        $dbPath = $this->tmpDir.'/om.sqlite';
        $connection = $this->omDatabaseFactory()->connectAndMigrate($dbPath);
        $obs = new ObservationRepository($connection);
        $gen = new MemoryGenerationRepository($connection);

        $obsId = str_repeat('1', 64);
        $refId = str_repeat('2', 64);
        $otherId = str_repeat('3', 64);

        $obs->commitChunkPartCoverage(
            coverageKey: 'cov-1',
            runId: 'run-current',
            boundaryKey: 'b1',
            sourceStartSeq: 1,
            sourceEndSeq: 5,
            chunkKey: 'chunk-1',
            partIndex: 1,
            partCount: 1,
            sourceDigest: 'd1',
            partDigest: 'p1',
            rendererVersion: '1',
            observerSchemaVersion: '1',
            observerModel: 'llama_cpp_test/test',
            observations: [[
                'observation_id' => $obsId,
                'content' => 'Observed exact source refs',
                'content_hash' => hash('sha256', 'Observed exact source refs'),
                'relevance' => 'medium',
                'timestamp' => '2026-07-28 13:00',
                'token_count' => 10,
                'source_refs_json' => json_encode([
                    ['run_id' => 'run-current', 'seq' => 2],
                    ['run_id' => 'run-current', 'seq' => 4],
                ], \JSON_THROW_ON_ERROR),
            ]],
            coveredAt: '2026-07-28T13:00:00+00:00',
        );
        $obs->commitChunkPartCoverage(
            coverageKey: 'cov-other',
            runId: 'run-other',
            boundaryKey: 'b1',
            sourceStartSeq: 1,
            sourceEndSeq: 1,
            chunkKey: 'chunk-o',
            partIndex: 1,
            partCount: 1,
            sourceDigest: 'do',
            partDigest: 'po',
            rendererVersion: '1',
            observerSchemaVersion: '1',
            observerModel: 'llama_cpp_test/test',
            observations: [[
                'observation_id' => $otherId,
                'content' => 'other run',
                'content_hash' => hash('sha256', 'other run'),
                'relevance' => 'low',
                'timestamp' => '2026-07-28 13:01',
                'token_count' => 4,
                'source_refs_json' => json_encode([['run_id' => 'run-other', 'seq' => 1]], \JSON_THROW_ON_ERROR),
            ]],
            coveredAt: '2026-07-28T13:01:00+00:00',
        );

        $gen->claimGeneration(
            generationId: 'gen-1',
            runId: 'run-current',
            triggerKind: MemoryGenerationRepository::TRIGGER_THRESHOLD,
            observationSetHash: hash('sha256', 'set'),
            reflectorModel: 'llama_cpp_test/test',
            reflectorSchemaVersion: '1',
            now: '2026-07-28T13:02:00+00:00',
            requiredStartSeq: 1,
            requiredEndSeq: 5,
        );
        $gen->commitSucceededGeneration(
            generationId: 'gen-1',
            runId: 'run-current',
            observationSetHash: hash('sha256', 'set'),
            reflectorModel: 'llama_cpp_test/test',
            reflectorSchemaVersion: '1',
            reflections: [[
                'reflection_id' => $refId,
                'content' => 'Stable preference',
                'supporting_observation_ids_json' => json_encode([$obsId], \JSON_THROW_ON_ERROR),
                'token_count' => 5,
            ]],
            retainedObservationIds: [$obsId],
            now: '2026-07-28T13:02:01+00:00',
        );

        $reader = new class implements SessionEventReaderInterface {
            public function readRange(string $runId, int $startSeq, int $endSeq): iterable
            {
                for ($seq = $startSeq; $seq <= $endSeq; ++$seq) {
                    yield new SessionEventDTO(
                        runId: $runId,
                        seq: $seq,
                        turnNo: 1,
                        type: 'message',
                        payload: ['seq' => $seq, 'text' => 'event-'.$seq],
                        createdAt: '2026-07-28T13:00:00+00:00',
                    );
                }
            }
        };

        $api = $this->api($this->tmpDir, $reader);
        $settings = OmSettings::fromArray([
            'storage' => ['database' => $dbPath],
            'observer' => ['model' => 'llama_cpp_test/test'],
            'reflector' => ['model' => 'llama_cpp_test/test'],
        ]);
        $handler = new RecallToolHandler(new OmQueryService($api, $settings));
        $accessor = new StackToolExecutionContextAccessor();
        $adapter = new ExtensionToolHandlerAdapter($handler, $accessor);

        $observationResult = $accessor->with(
            new ToolContext(
                runId: 'run-current',
                turnNo: 1,
                toolCallId: 'tc-1',
                toolName: 'recall',
                cancellationToken: new NullCancellationToken(),
                timeoutSeconds: null,
            ),
            static fn (): mixed => $adapter(['id' => $obsId]),
        );
        $this->assertIsArray($observationResult);
        $this->assertTrue($observationResult['ok']);
        $this->assertSame('observation', $observationResult['kind']);
        $this->assertCount(2, $observationResult['events']);
        $this->assertSame(2, $observationResult['events'][0]['seq']);
        $this->assertSame(4, $observationResult['events'][1]['seq']);

        $reflectionResult = $accessor->with(
            new ToolContext(
                runId: 'run-current',
                turnNo: 1,
                toolCallId: 'tc-2',
                toolName: 'recall',
                cancellationToken: new NullCancellationToken(),
                timeoutSeconds: null,
            ),
            static fn (): mixed => $adapter(['id' => $refId]),
        );
        $this->assertIsArray($reflectionResult);
        $this->assertTrue($reflectionResult['ok']);
        $this->assertSame('reflection', $reflectionResult['kind']);
        $this->assertSame([$obsId], $reflectionResult['supporting_observation_ids']);
        $this->assertCount(2, $reflectionResult['events']);

        $invalid = $accessor->with(
            new ToolContext(
                runId: 'run-current',
                turnNo: 1,
                toolCallId: 'tc-3',
                toolName: 'recall',
                cancellationToken: new NullCancellationToken(),
                timeoutSeconds: null,
            ),
            static fn (): mixed => $adapter(['id' => 'not-a-hash']),
        );
        $this->assertIsArray($invalid);
        $this->assertFalse($invalid['ok']);
        $this->assertSame('invalid_id', $invalid['error']);

        $cross = $accessor->with(
            new ToolContext(
                runId: 'run-current',
                turnNo: 1,
                toolCallId: 'tc-4',
                toolName: 'recall',
                cancellationToken: new NullCancellationToken(),
                timeoutSeconds: null,
            ),
            static fn (): mixed => $adapter(['id' => $otherId]),
        );
        $this->assertIsArray($cross);
        $this->assertFalse($cross['ok']);
        $this->assertSame('not_found', $cross['error']);

        // Reflection support ids that only exist in another run must not leak events.
        $refOtherSupport = str_repeat('4', 64);
        $connection->insert('om_reflection', [
            'reflection_id' => $refOtherSupport,
            'run_id' => 'run-current',
            'compaction_request_id' => 'foreign-support-case',
            'observation_set_hash' => hash('sha256', 'set-2'),
            'content' => 'reflection with foreign support',
            'supporting_observation_ids_json' => json_encode([$otherId], \JSON_THROW_ON_ERROR),
            'compression_level' => '0',
            'token_count' => 4,
            'reflector_model' => 'llama_cpp_test/test',
            'reflector_schema_version' => '1',
            'created_at' => '2026-07-28T13:03:00+00:00',
        ]);

        $foreignSupport = $accessor->with(
            new ToolContext(
                runId: 'run-current',
                turnNo: 1,
                toolCallId: 'tc-5',
                toolName: 'recall',
                cancellationToken: new NullCancellationToken(),
                timeoutSeconds: null,
            ),
            static fn (): mixed => $adapter(['id' => $refOtherSupport]),
        );
        $this->assertIsArray($foreignSupport);
        $this->assertTrue($foreignSupport['ok']);
        $this->assertSame('reflection', $foreignSupport['kind']);
        $this->assertSame([], $foreignSupport['source_refs']);
        $this->assertSame([], $foreignSupport['events']);
    }

    private function api(string $cwd, SessionEventReaderInterface $reader): ExtensionApiInterface
    {
        return new class($cwd, $reader) implements ExtensionApiInterface {
            public function __construct(
                private string $cwd,
                private SessionEventReaderInterface $reader,
            ) {
            }

            public function getCwd(): string
            {
                return $this->cwd;
            }

            public function getSettings(string $key): array
            {
                return [];
            }

            public function registerTool(ToolRegistrationDTO $tool): void
            {
            }

            public function registerToolCallHook(ToolCallHookInterface $hook): void
            {
            }

            public function registerToolResultHook(ToolResultHookInterface $hook): void
            {
            }

            public function registerToolCallRewriteHook(string $toolName, ToolCallRewriteHookInterface $hook): void
            {
            }

            public function registerPromptContributor(PromptContributorInterface $contributor): void
            {
            }

            public function registerCommand(CommandDefinitionDTO $definition, ExtensionCommandHandlerInterface $handler): void
            {
            }

            public function registerAfterTurnCommitHook(AfterTurnCommitHookInterface $hook): void
            {
            }

            public function registerBeforeCompactionHook(BeforeCompactionHookInterface $hook): void
            {
            }

            public function registerExtensionAgentJobHandler(string $handlerId, ExtensionAgentJobHandlerInterface $handler): void
            {
            }

            public function dispatchExtensionAgentJob(ExtensionAgentJobRequestDTO $request): void
            {
            }

            public function agent(): AgentRunnerInterface
            {
                throw new \LogicException('unused');
            }

            public function sessionEvents(): SessionEventReaderInterface
            {
                return $this->reader;
            }

            public function exec(): ExecInterface
            {
                throw new \LogicException('unused');
            }
        };
    }

    private function omDatabaseFactory(): OmDatabaseFactoryTestService
    {
        /** @var OmDatabaseFactoryTestService $service */
        $service = self::getContainer()->get('test.om_database_factory');

        return $service;
    }
}
