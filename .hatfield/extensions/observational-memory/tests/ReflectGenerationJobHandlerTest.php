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
use Ineersa\Hatfield\ExtensionApi\Compaction\BeforeCompactionHookInterface;
use Ineersa\Hatfield\ExtensionApi\Exec\ExecInterface;
use Ineersa\Hatfield\ExtensionApi\ExtensionApiInterface;
use Ineersa\Hatfield\ExtensionApi\Lifecycle\AfterTurnCommitHookInterface;
use Ineersa\Hatfield\ExtensionApi\Prompt\PromptContributorInterface;
use Ineersa\Hatfield\ExtensionApi\Session\SessionEventReaderInterface;
use Ineersa\Hatfield\ExtensionApi\Tool\ToolCallHookInterface;
use Ineersa\Hatfield\ExtensionApi\Tool\ToolCallRewriteHookInterface;
use Ineersa\Hatfield\ExtensionApi\Tool\ToolRegistrationDTO;
use Ineersa\Hatfield\ExtensionApi\Tool\ToolResultHookInterface;
use Ineersa\HatfieldExt\ObservationalMemory\Compaction\ReflectGenerationJobHandler;
use Ineersa\HatfieldExt\ObservationalMemory\Observer\OmTokenEstimator;
use Ineersa\HatfieldExt\ObservationalMemory\Runtime\OmSettings;
use Ineersa\HatfieldExt\ObservationalMemory\Storage\MemoryGenerationRepository;
use Ineersa\HatfieldExt\ObservationalMemory\Support\OmIdentity;
use Ineersa\HatfieldExt\ObservationalMemory\Tests\Support\OmDatabaseFactoryTestService;
use Psr\Log\NullLogger;

/**
 * Thesis: threshold Reflector claims generation idempotently, no-ops when under threshold,
 * and promotes active generation only on success.
 */
final class ReflectGenerationJobHandlerTest extends IsolatedKernelTestCase
{
    private string $projectDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->projectDir = TestDirectoryIsolation::createProjectTempDir('om-reflect-gen');
        TestDirectoryIsolation::createHatfieldTree($this->projectDir);
    }

    protected function tearDown(): void
    {
        TestDirectoryIsolation::removeDirectory($this->projectDir);
        parent::tearDown();
    }

    public function testThresholdNoopWhenUnderTokenGate(): void
    {
        $settings = OmSettings::fromArray([
            'observer' => ['model' => 'llama_cpp_test/test'],
            'reflector' => ['model' => 'llama_cpp_test/test', 'reflect_after_observation_tokens' => 40_000],
        ]);
        $paths = \Ineersa\HatfieldExt\ObservationalMemory\Runtime\OmPaths::fromSettings($settings, $this->projectDir);
        $connection = $this->omDatabaseFactory()->connectAndMigrate($paths->databasePath, new NullLogger());

        $content = 'small observation';
        $obsId = OmIdentity::observationId('run-t', '1', '2026-07-26 12:00', $content, [['run_id' => 'run-t', 'seq' => 1]]);
        $connection->insert('om_observation', [
            'observation_id' => $obsId,
            'run_id' => 'run-t',
            'boundary_key' => 'b1',
            'source_start_seq' => 1,
            'source_end_seq' => 1,
            'source_refs_json' => '[{"run_id":"run-t","seq":1}]',
            'content' => $content,
            'content_hash' => hash('sha256', $content),
            'relevance' => 'high',
            'timestamp' => '2026-07-26 12:00',
            'token_count' => OmTokenEstimator::estimate($content),
            'observer_model' => 'llama_cpp_test/test',
            'observer_schema_version' => '1',
            'created_at' => '2026-07-26T12:00:00+00:00',
        ]);

        $setHash = OmIdentity::observationSetHash('run-t', [$obsId]);
        $generationId = OmIdentity::thresholdGenerationId('run-t', null, $setHash, 'llama_cpp_test/test', '1');

        $agentCalls = 0;
        $api = $this->api($settings, static function () use (&$agentCalls): void {
            ++$agentCalls;
        });

        $handler = new ReflectGenerationJobHandler(new NullLogger());
        $handler->handle($api, [
            'run_id' => 'run-t',
            'generation_id' => $generationId,
            'threshold_idempotency_key' => $generationId,
            'observation_set_hash' => $setHash,
            'prior_active_generation_id' => null,
            'reflector_model' => 'llama_cpp_test/test',
            'reflector_schema_version' => '1',
        ], 'job-t', 'run-t');

        $this->assertSame(0, $agentCalls);
        $status = (string) $connection->fetchOne(
            'SELECT status FROM om_memory_generation WHERE generation_id = ?',
            [$generationId],
        );
        $this->assertSame(MemoryGenerationRepository::STATUS_SUCCEEDED, $status);
        $active = $connection->fetchOne('SELECT generation_id FROM om_active_generation WHERE run_id = ?', ['run-t']);
        $this->assertFalse($active);
    }

    public function testThresholdReflectPromotesActiveGenerationAndIsIdempotent(): void
    {
        $settings = OmSettings::fromArray([
            'observer' => ['model' => 'llama_cpp_test/test'],
            'reflector' => ['model' => 'llama_cpp_test/test', 'reflect_after_observation_tokens' => 1],
        ]);
        $paths = \Ineersa\HatfieldExt\ObservationalMemory\Runtime\OmPaths::fromSettings($settings, $this->projectDir);
        $connection = $this->omDatabaseFactory()->connectAndMigrate($paths->databasePath, new NullLogger());

        $content = str_repeat('important observation about rollout flags ', 20);
        $obsId = OmIdentity::observationId('run-r', '1', '2026-07-26 12:00', $content, [['run_id' => 'run-r', 'seq' => 1]]);
        $connection->insert('om_observation', [
            'observation_id' => $obsId,
            'run_id' => 'run-r',
            'boundary_key' => 'b1',
            'source_start_seq' => 1,
            'source_end_seq' => 1,
            'source_refs_json' => '[{"run_id":"run-r","seq":1}]',
            'content' => $content,
            'content_hash' => hash('sha256', $content),
            'relevance' => 'critical',
            'timestamp' => '2026-07-26 12:00',
            'token_count' => OmTokenEstimator::estimate($content),
            'observer_model' => 'llama_cpp_test/test',
            'observer_schema_version' => '1',
            'created_at' => '2026-07-26T12:00:00+00:00',
        ]);

        $setHash = OmIdentity::observationSetHash('run-r', [$obsId]);
        $generationId = OmIdentity::thresholdGenerationId('run-r', null, $setHash, 'llama_cpp_test/test', '1');

        $agentCalls = 0;
        $api = $this->api($settings, static function (AgentCallRequestDTO $request) use (&$agentCalls, $obsId): void {
            ++$agentCalls;
            $tool = $request->tools[0] ?? null;
            if (null === $tool || 'record_reflections' !== $tool->name) {
                throw new \RuntimeException('expected record_reflections');
            }
            ($tool->handler)([
                'reflections' => [
                    [
                        'content' => 'User requires feature-flag rollouts for risky releases',
                        'supporting_observation_ids' => [$obsId],
                    ],
                ],
                'retained_observation_ids' => [$obsId],
            ]);
        });

        $handler = new ReflectGenerationJobHandler(new NullLogger());
        $payload = [
            'run_id' => 'run-r',
            'generation_id' => $generationId,
            'threshold_idempotency_key' => $generationId,
            'observation_set_hash' => $setHash,
            'prior_active_generation_id' => null,
            'reflector_model' => 'llama_cpp_test/test',
            'reflector_schema_version' => '1',
        ];
        $handler->handle($api, $payload, 'job-r', 'run-r');
        $this->assertSame(1, $agentCalls);
        $active = (string) $connection->fetchOne(
            'SELECT generation_id FROM om_active_generation WHERE run_id = ?',
            ['run-r'],
        );
        $this->assertSame($generationId, $active);

        $handler->handle($api, $payload, 'job-r-2', 'run-r');
        $this->assertSame(1, $agentCalls, 'succeeded generation redelivery must not re-run model');
    }

    /**
     * @param callable(AgentCallRequestDTO):void $onAgentRun
     */
    private function api(OmSettings $settings, callable $onAgentRun): ExtensionApiInterface
    {
        $cwd = $this->projectDir;

        return new class($cwd, $settings, $onAgentRun) implements ExtensionApiInterface {
            public function __construct(
                private readonly string $cwd,
                private readonly OmSettings $settings,
                private readonly mixed $onAgentRun,
            ) {
            }

            public function registerTool(ToolRegistrationDTO $tool): void
            {
            }

            public function registerToolCallHook(ToolCallHookInterface $hook): void
            {
            }

            public function registerToolCallRewriteHook(string $toolName, ToolCallRewriteHookInterface $hook): void
            {
            }

            public function registerToolResultHook(ToolResultHookInterface $hook): void
            {
            }

            public function registerCommand(CommandDefinitionDTO $command, ExtensionCommandHandlerInterface $handler): void
            {
            }

            public function registerPromptContributor(PromptContributorInterface $contributor): void
            {
            }

            public function registerAfterTurnCommitHook(AfterTurnCommitHookInterface $hook): void
            {
            }

            public function registerBeforeCompactionHook(BeforeCompactionHookInterface $hook): void
            {
            }

            public function getSettings(string $key): array
            {
                if ('observational_memory' !== $key) {
                    return [];
                }

                return [
                    'storage' => ['database' => $this->settings->databasePath],
                    'observer' => [
                        'model' => $this->settings->observerModel,
                        'thinking_level' => $this->settings->observerThinkingLevel,
                        'context_window_ratio' => $this->settings->observerContextWindowRatio,
                        'renderer_version' => $this->settings->rendererVersion,
                        'schema_version' => $this->settings->observerSchemaVersion,
                    ],
                    'reflector' => [
                        'model' => $this->settings->reflectorModel,
                        'thinking_level' => $this->settings->reflectorThinkingLevel,
                        'context_window_ratio' => $this->settings->reflectorContextWindowRatio,
                        'reflect_after_observation_tokens' => $this->settings->reflectAfterObservationTokens,
                        'schema_version' => $this->settings->reflectorSchemaVersion,
                    ],
                    'pools' => [
                        'observations_max_tokens' => $this->settings->observationsMaxTokens,
                        'reflections_max_tokens' => $this->settings->reflectionsMaxTokens,
                    ],
                    'compaction' => [
                        'wait_timeout_seconds' => $this->settings->waitTimeoutSeconds,
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

            public function agent(): AgentRunnerInterface
            {
                $on = $this->onAgentRun;

                return new class($on) implements AgentRunnerInterface {
                    public function __construct(private readonly mixed $on)
                    {
                    }

                    public function run(AgentCallRequestDTO $request): void
                    {
                        ($this->on)($request);
                    }

                    public function contextWindow(string $exactModel): ?int
                    {
                        return 128000;
                    }
                };
            }

            public function sessionEvents(): SessionEventReaderInterface
            {
                throw new \LogicException('unused');
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
