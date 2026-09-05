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
use Ineersa\HatfieldExt\ObservationalMemory\Observer\OmTokenEstimator;
use Ineersa\HatfieldExt\ObservationalMemory\Support\OmIdentity;
use Ineersa\HatfieldExt\ObservationalMemory\Tests\Support\OmDatabaseFactoryTestService;
use Psr\Log\NullLogger;

/**
 * Thesis: threshold dispatch only after durable observe, when active tokens > 40k,
 * with deterministic generation/job id; suppressed when already running/succeeded for set.
 */
final class ObserveBoundaryThresholdDispatchTest extends IsolatedKernelTestCase
{
    private string $projectDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->projectDir = TestDirectoryIsolation::createProjectTempDir('om-threshold');
        TestDirectoryIsolation::createHatfieldTree($this->projectDir);
    }

    protected function tearDown(): void
    {
        TestDirectoryIsolation::removeDirectory($this->projectDir);
        parent::tearDown();
    }

    public function testDispatchesThresholdWhenActiveTokensExceedLimit(): void
    {
        // Content large enough that estimator > 40000 tokens: ceil(chars/4) > 40000 => chars > 160000.
        $content = str_repeat('a', 160_004);
        $this->assertGreaterThan(40_000, OmTokenEstimator::estimate($content));

        $events = [
            new SessionEventDTO('run-t', 1, 1, 'agent_command_applied', ['text' => 'hello'], '2026-07-26T00:00:00+00:00'),
            new SessionEventDTO('run-t', 2, 1, 'agent_end', ['reason' => 'completed'], '2026-07-26T00:00:01+00:00'),
        ];

        $dispatched = [];
        $api = $this->buildApi(
            events: $events,
            onAgentRun: static function (AgentCallRequestDTO $request) use ($content): void {
                $tool = $request->tools[0] ?? null;
                self::assertNotNull($tool);
                ($tool->handler)([
                    'observations' => [[
                        'timestamp' => '2026-07-26 00:00',
                        'content' => $content,
                        'relevance' => 'medium',
                        'source_refs' => [['run_id' => 'run-t', 'seq' => 1]],
                    ]],
                ]);
            },
            onDispatch: static function (ExtensionAgentJobRequestDTO $request) use (&$dispatched): void {
                $dispatched[] = $request;
            },
            reflectAfter: 40_000,
        );

        (new ObserveBoundaryJobHandler(new NullLogger()))->handle(
            $api,
            [
                'run_id' => 'run-t',
                'terminal_end_seq' => 2,
                'terminal_status' => 'completed',
                'renderer_version' => '1',
                'observer_schema_version' => '1',
            ],
            'job-observe',
            'run-t',
        );

        $this->assertCount(1, $dispatched);
        $this->assertSame(ObserveBoundaryJobHandler::REFLECT_HANDLER_ID, $dispatched[0]->handlerId);
        $this->assertSame('threshold', $dispatched[0]->payload['trigger']);
        $obsId = OmIdentity::observationId(
            'run-t',
            '1',
            '2026-07-26 00:00',
            $content,
            [['run_id' => 'run-t', 'seq' => 1]],
        );
        $setHash = OmIdentity::observationSetHash('run-t', [$obsId]);
        $this->assertSame($setHash, $dispatched[0]->payload['observation_set_hash']);
        $expectedGeneration = OmIdentity::thresholdGenerationId(
            'run-t',
            null,
            $setHash,
            'llama_cpp_test/test',
            '1',
        );
        $this->assertSame($expectedGeneration, $dispatched[0]->payload['generation_id']);
        $this->assertSame($expectedGeneration, $dispatched[0]->jobId);
    }

    public function testSuppressesThresholdWhenUnderTokenLimit(): void
    {
        $events = [
            new SessionEventDTO('run-s', 1, 1, 'agent_command_applied', ['text' => 'tiny'], '2026-07-26T00:00:00+00:00'),
            new SessionEventDTO('run-s', 2, 1, 'agent_end', ['reason' => 'completed'], '2026-07-26T00:00:01+00:00'),
        ];
        $dispatched = [];
        $api = $this->buildApi(
            events: $events,
            onAgentRun: static function (AgentCallRequestDTO $request): void {
                $tool = $request->tools[0] ?? null;
                self::assertNotNull($tool);
                ($tool->handler)([
                    'observations' => [[
                        'timestamp' => '2026-07-26 00:00',
                        'content' => 'small fact',
                        'relevance' => 'low',
                        'source_refs' => [['run_id' => 'run-s', 'seq' => 1]],
                    ]],
                ]);
            },
            onDispatch: static function (ExtensionAgentJobRequestDTO $request) use (&$dispatched): void {
                $dispatched[] = $request;
            },
            reflectAfter: 40_000,
        );

        (new ObserveBoundaryJobHandler(new NullLogger()))->handle(
            $api,
            [
                'run_id' => 'run-s',
                'terminal_end_seq' => 2,
                'terminal_status' => 'completed',
                'renderer_version' => '1',
                'observer_schema_version' => '1',
            ],
            'job-s',
            'run-s',
        );

        $this->assertSame([], $dispatched);
        $dbPath = $this->projectDir.'/.hatfield/extensions-data/observational-memory/om.sqlite';
        $connection = $this->omDatabaseFactory()->connect($dbPath, new NullLogger());
        $this->assertSame(1, (int) $connection->fetchOne('SELECT COUNT(*) FROM om_observation WHERE run_id = ?', ['run-s']));
    }

    public function testSuppressesThresholdRedispatchWhenFailedGenerationExistsForSet(): void
    {
        $content = str_repeat('b', 160_004);
        $this->assertGreaterThan(40_000, OmTokenEstimator::estimate($content));

        $events = [
            new SessionEventDTO('run-f', 1, 1, 'agent_command_applied', ['text' => 'hello'], '2026-07-26T00:00:00+00:00'),
            new SessionEventDTO('run-f', 2, 1, 'agent_end', ['reason' => 'completed'], '2026-07-26T00:00:01+00:00'),
        ];

        $dispatched = [];
        $api = $this->buildApi(
            events: $events,
            onAgentRun: static function (AgentCallRequestDTO $request) use ($content): void {
                $tool = $request->tools[0] ?? null;
                self::assertNotNull($tool);
                ($tool->handler)([
                    'observations' => [[
                        'timestamp' => '2026-07-26 00:00',
                        'content' => $content,
                        'relevance' => 'medium',
                        'source_refs' => [['run_id' => 'run-f', 'seq' => 1]],
                    ]],
                ]);
            },
            onDispatch: static function (ExtensionAgentJobRequestDTO $request) use (&$dispatched): void {
                $dispatched[] = $request;
            },
            reflectAfter: 40_000,
        );

        // First boundary: observe + dispatch threshold.
        (new ObserveBoundaryJobHandler(new NullLogger()))->handle(
            $api,
            [
                'run_id' => 'run-f',
                'terminal_end_seq' => 2,
                'terminal_status' => 'completed',
                'renderer_version' => '1',
                'observer_schema_version' => '1',
            ],
            'job-f1',
            'run-f',
        );
        $this->assertCount(1, $dispatched);

        $obsId = OmIdentity::observationId(
            'run-f',
            '1',
            '2026-07-26 00:00',
            $content,
            [['run_id' => 'run-f', 'seq' => 1]],
        );
        $setHash = OmIdentity::observationSetHash('run-f', [$obsId]);
        $generationId = (string) $dispatched[0]->payload['generation_id'];

        // Simulate durable Reflector failure for that exact set (Messenger max_retries exhausted).
        $dbPath = $this->projectDir.'/.hatfield/extensions-data/observational-memory/om.sqlite';
        $connection = $this->omDatabaseFactory()->connect($dbPath, new NullLogger());
        $connection->insert('om_memory_generation', [
            'generation_id' => $generationId,
            'run_id' => 'run-f',
            'trigger_kind' => 'threshold',
            'status' => 'failed',
            'observation_set_hash' => $setHash,
            'reflector_model' => 'llama_cpp_test/test',
            'reflector_schema_version' => '1',
            'threshold_idempotency_key' => $generationId,
            'required_start_seq' => 1,
            'required_end_seq' => 2,
            'compaction_request_id' => null,
            'request_fingerprint' => null,
            'failure_code' => 'tool_not_called',
            'created_at' => '2026-07-26T00:00:00+00:00',
            'completed_at' => '2026-07-26T00:00:01+00:00',
        ]);

        // Second boundary with same active set must not re-dispatch forever.
        (new ObserveBoundaryJobHandler(new NullLogger()))->handle(
            $api,
            [
                'run_id' => 'run-f',
                'terminal_end_seq' => 2,
                'terminal_status' => 'completed',
                'renderer_version' => '1',
                'observer_schema_version' => '1',
            ],
            'job-f2',
            'run-f',
        );
        $this->assertCount(1, $dispatched, 'failed generation for exact set must suppress new threshold dispatch');

        // claimGeneration still reclaims failed for the already-delivered Messenger retry.
        $genRepo = new \Ineersa\HatfieldExt\ObservationalMemory\Storage\MemoryGenerationRepository($connection);
        $this->assertTrue($genRepo->hasTerminalOrInFlightGenerationForSet('run-f', $setHash));
        $claim = $genRepo->claimGeneration(
            generationId: $generationId,
            runId: 'run-f',
            triggerKind: 'threshold',
            observationSetHash: $setHash,
            reflectorModel: 'llama_cpp_test/test',
            reflectorSchemaVersion: '1',
            now: '2026-07-26T00:01:00+00:00',
            thresholdIdempotencyKey: $generationId,
            requiredStartSeq: 1,
            requiredEndSeq: 2,
        );
        $this->assertSame('claimed', $claim['status']);
    }

    /**
     * @param list<SessionEventDTO>                      $events
     * @param callable(AgentCallRequestDTO):void         $onAgentRun
     * @param callable(ExtensionAgentJobRequestDTO):void $onDispatch
     */
    private function buildApi(array $events, callable $onAgentRun, callable $onDispatch, int $reflectAfter): ExtensionApiInterface
    {
        $cwd = $this->projectDir;

        return new class($cwd, $events, $onAgentRun, $onDispatch, $reflectAfter) implements ExtensionApiInterface {
            public function __construct(
                private readonly string $cwd,
                private readonly array $events,
                private readonly mixed $onAgentRun,
                private readonly mixed $onDispatch,
                private readonly int $reflectAfter,
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
                        'schema_version' => '1',
                        'renderer_version' => '1',
                        'context_window_ratio' => 0.65,
                    ],
                    'reflector' => [
                        'schema_version' => '1',
                        'context_window_ratio' => 0.65,
                        'reflect_after_observation_tokens' => $this->reflectAfter,
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

            public function registerSessionStartHook(\Ineersa\Hatfield\ExtensionApi\Lifecycle\AfterSessionStartHookInterface $hook): void
            {
            }

            public function registerBeforeCompactionHook(\Ineersa\Hatfield\ExtensionApi\Compaction\BeforeCompactionHookInterface $hook): void
            {
            }

            public function agent(): AgentRunnerInterface
            {
                $onAgentRun = $this->onAgentRun;

                return new class($onAgentRun) implements AgentRunnerInterface {
                    public function __construct(private readonly mixed $onAgentRun)
                    {
                    }

                    public function run(AgentCallRequestDTO $request): void
                    {
                        ($this->onAgentRun)($request);
                    }

                    public function contextWindow(string $exactModel): ?int
                    {
                        return 200_000;
                    }
                };
            }

            public function sessionEvents(): SessionEventReaderInterface
            {
                return new class($this->events) implements SessionEventReaderInterface {
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
                ($this->onDispatch)($request);
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
