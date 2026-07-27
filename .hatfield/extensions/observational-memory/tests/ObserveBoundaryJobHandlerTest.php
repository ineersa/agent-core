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
        $api = $this->buildApi(
            events: $events,
            onAgentRun: static function (AgentCallRequestDTO $request) use (&$lastRequest): void {
                $lastRequest = $request;
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

        $this->assertInstanceOf(AgentCallRequestDTO::class, $lastRequest);
        $this->assertSame(100, $lastRequest->maxToolCalls);
        $this->assertStringContainsString('Use feature flags', $lastRequest->input);
        $this->assertStringContainsString('CURRENT REFLECTIONS:', $lastRequest->input);
        $this->assertStringContainsString('Current local time fallback:', $lastRequest->input);
        $this->assertStringContainsString('observation agent for a coding assistant', $lastRequest->instructions);

        $dbPath = $this->projectDir.'/.hatfield/extensions-data/observational-memory/om.sqlite';
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
                    'observer' => [
                        'model' => 'llama_cpp_test/test',
                        'schema_version' => 'o1',
                        'renderer_version' => 'r1',
                        'context_window_ratio' => 0.65,
                    ],
                    'reflector' => [
                        'model' => 'llama_cpp_test/test',
                        'schema_version' => 'rv1',
                        'context_window_ratio' => 0.65,
                    ],
                    'pools' => [
                        'observations_max_tokens' => 30000,
                        'reflections_max_tokens' => 10000,
                    ],
                    'compaction' => [
                        'wait_timeout_seconds' => 180,
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
