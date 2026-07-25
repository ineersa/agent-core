<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\ObservationalMemory\Tests;

use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use Ineersa\Hatfield\ExtensionApi\Agent\AgentCallRequestDTO;
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
use Ineersa\HatfieldExt\ObservationalMemory\Compaction\BuildCompactionMemoryJobHandler;
use Ineersa\HatfieldExt\ObservationalMemory\Runtime\OmSettings;
use Ineersa\HatfieldExt\ObservationalMemory\Storage\CompactionRepository;
use Ineersa\HatfieldExt\ObservationalMemory\Storage\OmDatabaseFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Thesis: compaction worker repairs missed coverage, reflects, persists replacement,
 * and no-ops compatible redelivery without a second model call.
 */
final class BuildCompactionMemoryJobHandlerTest extends TestCase
{
    private string $projectDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->projectDir = TestDirectoryIsolation::createProjectTempDir('om-build-compact');
        TestDirectoryIsolation::createHatfieldTree($this->projectDir);
    }

    protected function tearDown(): void
    {
        TestDirectoryIsolation::removeDirectory($this->projectDir);
        parent::tearDown();
    }

    public function testCatchUpReflectAndIdempotentRedelivery(): void
    {
        $events = [
            new SessionEventDTO(
                runId: 'run-c',
                seq: 1,
                turnNo: 1,
                type: 'agent_command_applied',
                payload: ['kind' => 'prompt', 'text' => 'Ship behind a feature flag'],
                createdAt: '2026-07-25T00:00:00+00:00',
            ),
            new SessionEventDTO(
                runId: 'run-c',
                seq: 2,
                turnNo: 1,
                type: 'agent_end',
                payload: ['reason' => 'completed'],
                createdAt: '2026-07-25T00:00:01+00:00',
            ),
        ];

        $agentCalls = 0;
        $api = $this->buildApi($events, static function (AgentCallRequestDTO $request) use (&$agentCalls): void {
            ++$agentCalls;
            $tool = $request->tools[0] ?? null;
            if (null === $tool) {
                throw new \RuntimeException('missing tool');
            }
            if ('record_observations' === $tool->name) {
                ($tool->handler)([
                    'observations' => [
                        [
                            'content' => 'Prefer feature flags for rollout',
                            'relevance' => 90,
                            'source_refs' => [['run_id' => 'run-c', 'seq' => 1]],
                        ],
                    ],
                ]);

                return;
            }
            if ('record_reflections' === $tool->name) {
                $obsId = null;
                if (preg_match('/id=([a-f0-9]{64})/', $request->input, $m)) {
                    $obsId = $m[1];
                }
                if (null === $obsId) {
                    throw new \RuntimeException('observation id not found in reflector input');
                }
                ($tool->handler)([
                    'replacement_text' => 'Earlier work decided feature-flag rollouts.',
                    'reflections' => [
                        [
                            'content' => 'Use feature flags for risky releases',
                            'supporting_observation_ids' => [$obsId],
                            'compression_level' => 0,
                        ],
                    ],
                ]);

                return;
            }
            throw new \RuntimeException('unexpected tool '.$tool->name);
        });

        $settings = OmSettings::fromArray([
            'enabled' => true,
            'observer_model' => 'llama_cpp_test/test',
            'reflector_model' => 'llama_cpp_test/test',
            'renderer_version' => 'r1',
            'observer_schema_version' => 'o1',
        ]);
        $paths = \Ineersa\HatfieldExt\ObservationalMemory\Runtime\OmPaths::fromSettings($settings, $this->projectDir);
        $connection = OmDatabaseFactory::connectAndMigrate($paths->databasePath, new NullLogger());
        $repo = new CompactionRepository($connection);
        $requestId = 'req-1';
        $fingerprint = 'fp-1';
        $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(\DateTimeInterface::ATOM);
        $repo->ensureRequest($requestId, 'run-c', 1, 2, 2, $fingerprint, $now);

        $handler = new BuildCompactionMemoryJobHandler(new NullLogger());
        $payload = [
            'request_id' => $requestId,
            'run_id' => 'run-c',
            'required_start_seq' => 1,
            'required_end_seq' => 2,
            'request_fingerprint' => $fingerprint,
        ];
        $handler->handle($api, $payload, 'job-1', 'run-c');
        $this->assertSame(2, $agentCalls);

        $result = $repo->getResult($requestId);
        $this->assertNotNull($result);
        $this->assertSame(CompactionRepository::STATUS_SUCCEEDED, $result['status']);
        $this->assertSame('Earlier work decided feature-flag rollouts.', $result['replacement_text']);

        $refCount = (int) $connection->fetchOne(
            'SELECT COUNT(*) FROM om_reflection WHERE compaction_request_id = ?',
            [$requestId],
        );
        $this->assertSame(1, $refCount);

        $handler->handle($api, $payload, 'job-1-redeliver', 'run-c');
        $this->assertSame(2, $agentCalls, 'compatible redelivery must not re-run models');
    }

    public function testNoObservationsProducesDurableFailure(): void
    {
        $api = $this->buildApi([], static function (AgentCallRequestDTO $request): void {
            throw new \RuntimeException('agent should not be called without coverage range events for empty end=0 path');
        });
        // requiredEndSeq=0 is rejected by payload validation; use end=0 via direct failure path by
        // ensuring coverage complete with no observations is not possible without events.
        // For end>=1 with empty event stream, observeRange still runs agent. Force no_observations
        // by using end=0 after ensureRequest with end=0 is invalid. Instead insert coverage empty
        // through observing nothing on empty history with explicit empty tool call.
        $events = [
            new SessionEventDTO(
                runId: 'run-empty',
                seq: 1,
                turnNo: 1,
                type: 'agent_end',
                payload: ['reason' => 'completed'],
                createdAt: '2026-07-25T00:00:00+00:00',
            ),
        ];
        $api = $this->buildApi($events, static function (AgentCallRequestDTO $request): void {
            $tool = $request->tools[0] ?? null;
            if (null === $tool) {
                throw new \RuntimeException('missing tool');
            }
            if ('record_observations' === $tool->name) {
                ($tool->handler)(['observations' => []]);

                return;
            }
            throw new \RuntimeException('reflector should not run when no observations');
        });

        $settings = OmSettings::fromArray([
            'enabled' => true,
            'observer_model' => 'llama_cpp_test/test',
            'reflector_model' => 'llama_cpp_test/test',
        ]);
        $paths = \Ineersa\HatfieldExt\ObservationalMemory\Runtime\OmPaths::fromSettings($settings, $this->projectDir);
        $connection = OmDatabaseFactory::connectAndMigrate($paths->databasePath, new NullLogger());
        $repo = new CompactionRepository($connection);
        $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(\DateTimeInterface::ATOM);
        $repo->ensureRequest('req-empty', 'run-empty', 1, 1, 1, 'fp-empty', $now);

        $handler = new BuildCompactionMemoryJobHandler(new NullLogger());
        $handler->handle($api, [
            'request_id' => 'req-empty',
            'run_id' => 'run-empty',
            'required_start_seq' => 1,
            'required_end_seq' => 1,
            'request_fingerprint' => 'fp-empty',
        ], 'job-empty', 'run-empty');

        $result = $repo->getResult('req-empty');
        $this->assertNotNull($result);
        $this->assertSame(CompactionRepository::STATUS_FAILED, $result['status']);
        $this->assertSame('no_observations', $result['failure_code']);
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
                return [
                    'enabled' => true,
                    'observer_model' => 'llama_cpp_test/test',
                    'reflector_model' => 'llama_cpp_test/test',
                    'renderer_version' => 'r1',
                    'observer_schema_version' => 'o1',
                    'max_observations' => 5,
                    'content_max_chars' => 500,
                    'tool_result_max_chars' => 4000,
                    'observer_input_budget_tokens' => 50_000,
                    'reflector_input_budget_tokens' => 50_000,
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

            public function registerBeforeCompactionHook(BeforeCompactionHookInterface $hook): void
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
}
