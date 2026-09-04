<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\ObservationalMemory\Tests;

use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;
use Ineersa\Hatfield\ExtensionApi\Agent\AgentCallRequestDTO;
use Ineersa\Hatfield\ExtensionApi\Agent\AgentRunnerInterface;
use Ineersa\Hatfield\ExtensionApi\Agent\ExtensionAgentJobHandlerInterface;
use Ineersa\Hatfield\ExtensionApi\Agent\ExtensionAgentJobRequestDTO;
use Ineersa\Hatfield\ExtensionApi\Command\CommandDefinitionDTO;
use Ineersa\Hatfield\ExtensionApi\Command\ExtensionCommandHandlerInterface;
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
use Ineersa\HatfieldExt\ObservationalMemory\Observer\ObserveBoundaryJobHandler;
use Ineersa\HatfieldExt\ObservationalMemory\Storage\ObservationRepository;
use Ineersa\HatfieldExt\ObservationalMemory\Tests\Support\OmDatabaseFactoryTestService;
use Psr\Log\NullLogger;

/**
 * Thesis: async ObserveBoundaryJobHandler renders range, invokes agent with record_observations,
 * and persists zero-or-more observations plus coverage watermark.
 */
final class ObserveBoundaryJobHandlerTest extends IsolatedKernelTestCase
{
    private string $projectDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->projectDir = TestDirectoryIsolation::createProjectTempDir('om-observe-job');
        TestDirectoryIsolation::createHatfieldTree($this->projectDir);
    }

    protected function tearDown(): void
    {
        TestDirectoryIsolation::removeDirectory($this->projectDir);
        parent::tearDown();
    }

    public function testPersistsObservationsAndCoverageFromStubAgent(): void
    {
        $events = [
            new SessionEventDTO(
                runId: 'run-1',
                seq: 1,
                turnNo: 1,
                type: 'agent_command_applied',
                payload: ['kind' => 'prompt', 'text' => 'Use feature flags'],
                createdAt: '2026-07-23T00:00:00+00:00',
            ),
            new SessionEventDTO(
                runId: 'run-1',
                seq: 2,
                turnNo: 1,
                type: 'agent_end',
                payload: ['reason' => 'completed'],
                createdAt: '2026-07-23T00:00:01+00:00',
            ),
        ];

        $lastRequest = null;
        $dbPath = $this->projectDir.'/.hatfield/extensions-data/observational-memory/om.sqlite';
        $seenActivityStage = null;
        $seenActivityTokens = null;
        $api = $this->buildApi(
            events: $events,
            onAgentRun: function (AgentCallRequestDTO $request) use (&$lastRequest, &$seenActivityStage, &$seenActivityTokens, $dbPath): void {
                $lastRequest = $request;
                $connection = $this->omDatabaseFactory()->connect($dbPath, new NullLogger());
                $activity = (new \Ineersa\HatfieldExt\ObservationalMemory\Storage\ActivityRepository($connection))->findFresh('run-1');
                $seenActivityStage = $activity['stage'] ?? null;
                $seenActivityTokens = $activity['current_tokens'] ?? null;
                $tool = $request->tools[0] ?? null;
                if (null === $tool) {
                    throw new \RuntimeException('expected record_observations tool');
                }
                ($tool->handler)([
                    'observations' => [
                        [
                            'timestamp' => '2026-07-23 00:00',
                            'content' => 'Prefer feature flags for rollout',
                            'relevance' => 'high',
                            'source_refs' => [
                                ['run_id' => 'run-1', 'seq' => 1],
                            ],
                        ],
                    ],
                ]);
            },
        );

        $handler = new ObserveBoundaryJobHandler(new NullLogger());
        $handler->handle(
            $api,
            [
                'run_id' => 'run-1',
                'terminal_end_seq' => 2,
                'terminal_status' => 'completed',
                'renderer_version' => 'r1',
                'observer_schema_version' => 'o1',
            ],
            'job-1',
            'run-1',
        );

        $this->assertSame('observer', $seenActivityStage);
        $this->assertIsInt($seenActivityTokens);
        $this->assertGreaterThan(0, $seenActivityTokens);
        $cleared = (new \Ineersa\HatfieldExt\ObservationalMemory\Storage\ActivityRepository(
            $this->omDatabaseFactory()->connect($dbPath, new NullLogger()),
        ))->findFresh('run-1');
        $this->assertNull($cleared, 'observer finally must clear activity');

        $this->assertInstanceOf(AgentCallRequestDTO::class, $lastRequest);
        $this->assertSame(6, $lastRequest->maxToolCalls);
        $this->assertStringContainsString('Use feature flags', $lastRequest->input);
        $this->assertStringContainsString('CURRENT REFLECTIONS:', $lastRequest->input);
        $this->assertStringContainsString('Current local time fallback:', $lastRequest->input);
        $this->assertStringContainsString('observation agent for a coding assistant', $lastRequest->instructions);

        $this->assertFileExists($dbPath);
        $this->assertSame('0700', substr(\sprintf('%o', fileperms(\dirname($dbPath))), -4));

        $connection = $this->omDatabaseFactory()->connect($dbPath, new NullLogger());
        $repo = new ObservationRepository($connection);
        $this->assertSame(2, $repo->contiguousCoveredEndSeq('run-1', 'r1', 'o1'));

        $count = (int) $connection->fetchOne('SELECT COUNT(*) FROM om_observation WHERE run_id = ?', ['run-1']);
        $this->assertSame(1, $count);
        $content = (string) $connection->fetchOne('SELECT content FROM om_observation WHERE run_id = ?', ['run-1']);
        $this->assertSame('Prefer feature flags for rollout', $content);
    }

    public function testZeroObservationCoverageIsPersisted(): void
    {
        $events = [
            new SessionEventDTO(
                runId: 'run-2',
                seq: 1,
                turnNo: 1,
                type: 'agent_end',
                payload: ['reason' => 'completed'],
                createdAt: '2026-07-23T00:00:00+00:00',
            ),
        ];

        $api = $this->buildApi(
            events: $events,
            onAgentRun: static function (AgentCallRequestDTO $request): void {
                $tool = $request->tools[0] ?? null;
                if (null === $tool) {
                    throw new \RuntimeException('expected record_observations tool');
                }
                ($tool->handler)(['observations' => []]);
            },
        );

        $handler = new ObserveBoundaryJobHandler(new NullLogger());
        $handler->handle(
            $api,
            [
                'run_id' => 'run-2',
                'terminal_end_seq' => 1,
                'terminal_status' => 'completed',
                'renderer_version' => 'r1',
                'observer_schema_version' => 'o1',
            ],
            'job-2',
            'run-2',
        );

        $dbPath = $this->projectDir.'/.hatfield/extensions-data/observational-memory/om.sqlite';
        $connection = $this->omDatabaseFactory()->connect($dbPath, new NullLogger());
        $repo = new ObservationRepository($connection);
        $this->assertSame(1, $repo->contiguousCoveredEndSeq('run-2', 'r1', 'o1'));
        $obs = (int) $connection->fetchOne('SELECT COUNT(*) FROM om_observation WHERE run_id = ?', ['run-2']);
        $this->assertSame(0, $obs);
        $covCount = (int) $connection->fetchOne(
            'SELECT observation_count FROM om_coverage WHERE run_id = ?',
            ['run-2'],
        );
        $this->assertSame(0, $covCount);
    }

    /**
     * Thesis: after contiguous coverage through 82, observe 83..88 where 83 is
     * non-renderable control (agent_command_queued) and 84+ are content must persist
     * coverage through 88 without a canonical hole (83 covered, not rendered/cited).
     * A later terminal through 90 must only observe the new 89..90 range — not re-run
     * the already-covered 84+ content (manual session-2 failure shape).
     */
    public function testNonRenderableControlSeqDoesNotLeaveCanonicalCoverageGap(): void
    {
        $dbPath = $this->projectDir.'/.hatfield/extensions-data/observational-memory/om.sqlite';
        // Seed requires migrated schema before handle() opens the same path.
        $connection = $this->omDatabaseFactory()->connectAndMigrate($dbPath, new NullLogger());
        $repo = new ObservationRepository($connection);

        // Seed prior contiguous coverage 1..82 (session-2 shape) via repository.
        $repo->commitChunkPartCoverage(
            coverageKey: 'seed-cov-1-82',
            runId: 'run-gap',
            boundaryKey: 'seed-chunk-1-82',
            sourceStartSeq: 1,
            sourceEndSeq: 82,
            chunkKey: 'seed-chunk-1-82',
            partIndex: 1,
            partCount: 1,
            sourceDigest: 'seed-source-digest',
            partDigest: 'seed-part-digest',
            rendererVersion: 'r1',
            observerSchemaVersion: 'o1',
            observerModel: 'llama_cpp_test/test',
            observations: [],
            coveredAt: '2026-07-27T22:00:00+00:00',
        );
        $this->assertSame(82, $repo->contiguousCoveredEndSeq('run-gap', 'r1', 'o1'));

        $events = [
            new SessionEventDTO(
                runId: 'run-gap',
                seq: 83,
                turnNo: 2,
                type: 'agent_command_queued',
                payload: ['kind' => 'prompt'],
                createdAt: '2026-07-27T22:01:00+00:00',
            ),
            new SessionEventDTO(
                runId: 'run-gap',
                seq: 84,
                turnNo: 2,
                type: 'agent_command_applied',
                payload: ['kind' => 'prompt', 'text' => 'Follow-up about include_archive'],
                createdAt: '2026-07-27T22:01:01+00:00',
            ),
            new SessionEventDTO(
                runId: 'run-gap',
                seq: 85,
                turnNo: 2,
                type: 'llm_step_completed',
                payload: [
                    'assistant_message' => [
                        'role' => 'assistant',
                        'content' => 'include_archive is unnecessary for TODO listing',
                    ],
                ],
                createdAt: '2026-07-27T22:01:02+00:00',
            ),
            new SessionEventDTO(
                runId: 'run-gap',
                seq: 86,
                turnNo: 2,
                type: 'agent_end',
                payload: ['reason' => 'completed'],
                createdAt: '2026-07-27T22:01:03+00:00',
            ),
            new SessionEventDTO(
                runId: 'run-gap',
                seq: 87,
                turnNo: 2,
                type: 'agent_command_applied',
                payload: ['kind' => 'prompt', 'text' => 'Proceed with four-phase plan'],
                createdAt: '2026-07-27T22:01:04+00:00',
            ),
            new SessionEventDTO(
                runId: 'run-gap',
                seq: 88,
                turnNo: 2,
                type: 'agent_end',
                payload: ['reason' => 'completed'],
                createdAt: '2026-07-27T22:01:05+00:00',
            ),
            new SessionEventDTO(
                runId: 'run-gap',
                seq: 89,
                turnNo: 3,
                type: 'agent_command_applied',
                payload: ['kind' => 'prompt', 'text' => 'Implement phase one only'],
                createdAt: '2026-07-27T22:02:00+00:00',
            ),
            new SessionEventDTO(
                runId: 'run-gap',
                seq: 90,
                turnNo: 3,
                type: 'agent_end',
                payload: ['reason' => 'completed'],
                createdAt: '2026-07-27T22:02:01+00:00',
            ),
        ];

        $agentCalls = 0;
        /** @var list<AgentCallRequestDTO> $requests */
        $requests = [];
        $api = $this->buildApi(
            events: $events,
            onAgentRun: static function (AgentCallRequestDTO $request) use (&$agentCalls, &$requests): void {
                ++$agentCalls;
                $requests[] = $request;
                $tool = $request->tools[0] ?? null;
                if (null === $tool) {
                    throw new \RuntimeException('expected record_observations tool');
                }
                $seq = 1 === $agentCalls ? 84 : 89;
                $content = 1 === $agentCalls
                    ? 'User asked about include_archive for TODO listing'
                    : 'User asked to implement phase one only';
                ($tool->handler)([
                    'observations' => [[
                        'timestamp' => '2026-07-27 22:01',
                        'content' => $content,
                        'relevance' => 'medium',
                        'source_refs' => [
                            ['run_id' => 'run-gap', 'seq' => $seq],
                        ],
                    ]],
                ]);
            },
        );

        $handler = new ObserveBoundaryJobHandler(new NullLogger());
        $handler->handle($api, [
            'run_id' => 'run-gap',
            'terminal_end_seq' => 88,
            'terminal_status' => 'completed',
            'renderer_version' => 'r1',
            'observer_schema_version' => 'o1',
        ], 'job-gap-1', 'run-gap');

        $this->assertSame(1, $agentCalls, 'Observer must run once for the uncovered 83..88 range');
        $firstRequest = $requests[0] ?? null;
        $this->assertInstanceOf(AgentCallRequestDTO::class, $firstRequest);
        $this->assertStringContainsString('include_archive', $firstRequest->input);
        $this->assertStringContainsString('[Source entry id: run-gap:84]', $firstRequest->input);
        $this->assertStringNotContainsString('[Source entry id: run-gap:83]', $firstRequest->input);
        $this->assertStringNotContainsString('agent_command_queued', $firstRequest->input);
        $this->assertStringNotContainsString('Implement phase one only', $firstRequest->input);

        $this->assertSame(88, $repo->contiguousCoveredEndSeq('run-gap', 'r1', 'o1'));

        $coverageRows = $connection->fetchAllAssociative(
            'SELECT source_start_seq, source_end_seq, observation_count
             FROM om_coverage
             WHERE run_id = ? AND source_start_seq >= 83
             ORDER BY source_start_seq, source_end_seq',
            ['run-gap'],
        );
        $this->assertNotSame([], $coverageRows);
        $this->assertSame(83, (int) $coverageRows[0]['source_start_seq']);
        $this->assertSame(88, (int) $coverageRows[\count($coverageRows) - 1]['source_end_seq']);

        // Later terminal past the repaired watermark must observe only the new tail.
        // Without the coverage-range fix, contiguous end stuck at 82 and every later
        // job re-observed overlapping 84+ content (manual session 2).
        $handler->handle($api, [
            'run_id' => 'run-gap',
            'terminal_end_seq' => 90,
            'terminal_status' => 'completed',
            'renderer_version' => 'r1',
            'observer_schema_version' => 'o1',
        ], 'job-gap-2', 'run-gap');

        $this->assertSame(2, $agentCalls, 'later terminal 90 must invoke Observer once more for 89..90 only');
        $secondRequest = $requests[1] ?? null;
        $this->assertInstanceOf(AgentCallRequestDTO::class, $secondRequest);
        // Prior observation text may appear under CURRENT OBSERVATIONS (correct).
        // The NEW source chunk must only contain the post-88 tail.
        $newSourcePos = strpos($secondRequest->input, 'NEW SOURCE-ADDRESSED CONVERSATION CHUNK:');
        $this->assertNotFalse($newSourcePos);
        $newSource = substr($secondRequest->input, $newSourcePos);
        $this->assertStringContainsString('Implement phase one only', $newSource);
        $this->assertStringContainsString('[Source entry id: run-gap:89]', $newSource);
        $this->assertStringNotContainsString('include_archive', $newSource);
        $this->assertStringNotContainsString('[Source entry id: run-gap:84]', $newSource);
        $this->assertStringNotContainsString('[Source entry id: run-gap:83]', $newSource);
        $this->assertSame(90, $repo->contiguousCoveredEndSeq('run-gap', 'r1', 'o1'));
    }

    /**
     * @param list<SessionEventDTO>              $events
     * @param callable(AgentCallRequestDTO):void $onAgentRun
     */
    private function buildApi(array $events, callable $onAgentRun): ExtensionApiInterface
    {
        $cwd = $this->projectDir;

        return new class($cwd, $events, $onAgentRun) implements ExtensionApiInterface {
            /**
             * @param list<SessionEventDTO>              $events
             * @param callable(AgentCallRequestDTO):void $onAgentRun
             */
            public function __construct(
                private readonly string $cwd,
                private readonly array $events,
                private readonly mixed $onAgentRun,
            ) {
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

            public function getSettings(string $key): array
            {
                if ('observational_memory' !== $key) {
                    return [];
                }

                return [
                    'model' => 'llama_cpp_test/test',
                    'observer' => [
                        'schema_version' => 'o1',
                        'renderer_version' => 'r1',
                        'context_window_ratio' => 0.65,
                    ],
                    'reflector' => [
                        'schema_version' => 'rv1',
                        'context_window_ratio' => 0.65,
                    ],
                    'pools' => [
                        'observations_max_tokens' => 30000,
                    ],
                ];
            }

            public function getCwd(): string
            {
                return $this->cwd;
            }

            public function exec(): ExecInterface
            {
                throw new \LogicException('unused');
            }

            public function registerPromptContributor(PromptContributorInterface $contributor): void
            {
            }

            public function registerSkill(string $skillDirectory): void
            {
            }

            public function registerCommand(CommandDefinitionDTO $definition, ExtensionCommandHandlerInterface $handler): void
            {
            }

            public function registerToolCallRewriteHook(string $toolName, ToolCallRewriteHookInterface $hook): void
            {
            }

            public function registerAfterTurnCommitHook(AfterTurnCommitHookInterface $hook): void
            {
            }

            public function registerBeforeCompactionHook(\Ineersa\Hatfield\ExtensionApi\Compaction\BeforeCompactionHookInterface $hook): void
            {
            }

            public function agent(): AgentRunnerInterface
            {
                $onAgentRun = $this->onAgentRun;

                return new class($onAgentRun) implements AgentRunnerInterface {
                    /**
                     * @param callable(AgentCallRequestDTO):void $onAgentRun
                     */
                    public function __construct(private readonly mixed $onAgentRun)
                    {
                    }

                    public function run(AgentCallRequestDTO $request): void
                    {
                        ($this->onAgentRun)($request);
                    }

                    public function contextWindow(string $exactModel): ?int
                    {
                        return 128000;
                    }
                };
            }

            public function sessionEvents(): SessionEventReaderInterface
            {
                return new class($this->events) implements SessionEventReaderInterface {
                    /**
                     * @param list<SessionEventDTO> $events
                     */
                    public function __construct(private readonly array $events)
                    {
                    }

                    public function readRange(string $runId, int $startSeq, int $endSeq): iterable
                    {
                        foreach ($this->events as $event) {
                            if ($event->runId === $runId && $event->seq >= $startSeq && $event->seq <= $endSeq) {
                                yield $event;
                            }
                        }
                    }
                };
            }

            public function registerExtensionAgentJobHandler(string $handlerId, ExtensionAgentJobHandlerInterface $handler): void
            {
            }

            public function dispatchExtensionAgentJob(ExtensionAgentJobRequestDTO $request): void
            {
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
