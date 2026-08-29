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
use Symfony\Component\Clock\MockClock;

/**
 * Thesis: threshold job runs shared-model delta Reflector then conditional Dropper,
 * commits prior+new reflections and active-minus-dropped once; zero new refs skips Dropper;
 * redelivery is idempotent.
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
            'model' => 'llama_cpp_test/test',
            'reflector' => ['reflect_after_observation_tokens' => 40_000],
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

    public function testCompletedAtUsesFreshTimestampAfterClaim(): void
    {
        $settings = OmSettings::fromArray([
            'model' => 'llama_cpp_test/test',
            'reflector' => ['reflect_after_observation_tokens' => 40_000],
        ]);
        $paths = \Ineersa\HatfieldExt\ObservationalMemory\Runtime\OmPaths::fromSettings($settings, $this->projectDir);
        $connection = $this->omDatabaseFactory()->connectAndMigrate($paths->databasePath, new NullLogger());

        $content = 'small observation for timestamp proof';
        $obsId = OmIdentity::observationId('run-ts', '1', '2026-07-26 12:00', $content, [['run_id' => 'run-ts', 'seq' => 1]]);
        $connection->insert('om_observation', [
            'observation_id' => $obsId,
            'run_id' => 'run-ts',
            'boundary_key' => 'b1',
            'source_start_seq' => 1,
            'source_end_seq' => 1,
            'source_refs_json' => '[{"run_id":"run-ts","seq":1}]',
            'content' => $content,
            'content_hash' => hash('sha256', $content),
            'relevance' => 'high',
            'timestamp' => '2026-07-26 12:00',
            'token_count' => OmTokenEstimator::estimate($content),
            'observer_model' => 'llama_cpp_test/test',
            'observer_schema_version' => '1',
            'created_at' => '2026-07-26T12:00:00+00:00',
        ]);

        $setHash = OmIdentity::observationSetHash('run-ts', [$obsId]);
        $generationId = OmIdentity::thresholdGenerationId('run-ts', null, $setHash, 'llama_cpp_test/test', '1');

        $base = new MockClock('2026-07-30T12:00:00+00:00');
        $clock = new class($base) implements \Symfony\Component\Clock\ClockInterface {
            private int $hits = 0;

            public function __construct(private MockClock $inner)
            {
            }

            public function now(): \DateTimeImmutable
            {
                ++$this->hits;
                if ($this->hits > 1) {
                    $this->inner->modify('+95 seconds');
                }

                return $this->inner->now();
            }

            public function sleep(float|int $seconds): void
            {
                $this->inner->sleep($seconds);
            }

            public function withTimeZone(\DateTimeZone|string $timezone): static
            {
                throw new \LogicException('unused');
            }
        };

        $api = $this->api($settings, static function (): void {
            throw new \RuntimeException('model must not run for under-threshold noop');
        });
        $handler = new ReflectGenerationJobHandler(new NullLogger(), $clock);
        $handler->handle($api, [
            'run_id' => 'run-ts',
            'generation_id' => $generationId,
            'threshold_idempotency_key' => $generationId,
            'observation_set_hash' => $setHash,
            'prior_active_generation_id' => null,
            'reflector_model' => 'llama_cpp_test/test',
            'reflector_schema_version' => '1',
        ], 'job-ts', 'run-ts');

        $row = $connection->fetchAssociative(
            'SELECT created_at, completed_at, status FROM om_memory_generation WHERE generation_id = ?',
            [$generationId],
        );
        $this->assertIsArray($row);
        $this->assertSame(MemoryGenerationRepository::STATUS_SUCCEEDED, $row['status']);
        $this->assertSame('2026-07-30T12:00:00+00:00', $row['created_at']);
        $this->assertSame('2026-07-30T12:01:35+00:00', $row['completed_at']);
        $this->assertNotSame($row['created_at'], $row['completed_at']);
    }

    public function testDeltaReflectPromotesPriorPlusNewWithoutDropperWhenPoolUnderMax(): void
    {
        $settings = OmSettings::fromArray([
            'model' => 'llama_cpp_test/test',
            'reflector' => ['reflect_after_observation_tokens' => 1],
            'pools' => ['observations_max_tokens' => 30_000],
        ]);
        $paths = \Ineersa\HatfieldExt\ObservationalMemory\Runtime\OmPaths::fromSettings($settings, $this->projectDir);
        $connection = $this->omDatabaseFactory()->connectAndMigrate($paths->databasePath, new NullLogger());

        $priorContent = 'Prior durable user preference for short answers';
        $priorId = OmIdentity::reflectionId('run-r', '1', $priorContent, ['obs-prior']);
        $connection->insert('om_reflection', [
            'reflection_id' => $priorId,
            'run_id' => 'run-r',
            'compaction_request_id' => '',
            'observation_set_hash' => 'prior-hash',
            'content' => $priorContent,
            'supporting_observation_ids_json' => '["obs-prior"]',
            'compression_level' => '0',
            'token_count' => OmTokenEstimator::estimate($priorContent),
            'reflector_model' => 'llama_cpp_test/test',
            'reflector_schema_version' => '1',
            'created_at' => '2026-07-26T11:00:00+00:00',
        ]);
        $priorGen = 'prior-gen-run-r';
        $connection->insert('om_memory_generation', [
            'generation_id' => $priorGen,
            'run_id' => 'run-r',
            'trigger_kind' => MemoryGenerationRepository::TRIGGER_THRESHOLD,
            'status' => MemoryGenerationRepository::STATUS_SUCCEEDED,
            'observation_set_hash' => 'prior-hash',
            'reflector_model' => 'llama_cpp_test/test',
            'reflector_schema_version' => '1',
            'required_start_seq' => 1,
            'required_end_seq' => 1,
            'created_at' => '2026-07-26T11:00:00+00:00',
            'completed_at' => '2026-07-26T11:00:00+00:00',
            'threshold_idempotency_key' => $priorGen,
        ]);
        $connection->insert('om_generation_reflection', [
            'generation_id' => $priorGen,
            'reflection_id' => $priorId,
            'position' => 0,
        ]);
        $connection->insert('om_active_generation', [
            'run_id' => 'run-r',
            'generation_id' => $priorGen,
        ]);

        $content = str_repeat('important observation about rollout flags ', 20);
        $obsId = OmIdentity::observationId('run-r', '1', '2026-07-26 12:00', $content, [['run_id' => 'run-r', 'seq' => 2]]);
        $connection->insert('om_observation', [
            'observation_id' => $obsId,
            'run_id' => 'run-r',
            'boundary_key' => 'b1',
            'source_start_seq' => 2,
            'source_end_seq' => 2,
            'source_refs_json' => '[{"run_id":"run-r","seq":2}]',
            'content' => $content,
            'content_hash' => hash('sha256', $content),
            'relevance' => 'critical',
            'timestamp' => '2026-07-26 12:00',
            'token_count' => OmTokenEstimator::estimate($content),
            'observer_model' => 'llama_cpp_test/test',
            'observer_schema_version' => '1',
            'created_at' => '2026-07-26T12:00:00+00:00',
        ]);
        // Prior retained obs so active set is prior retained UNION new.
        $connection->insert('om_observation', [
            'observation_id' => 'obs-prior',
            'run_id' => 'run-r',
            'boundary_key' => 'b0',
            'source_start_seq' => 1,
            'source_end_seq' => 1,
            'source_refs_json' => '[{"run_id":"run-r","seq":1}]',
            'content' => 'prior observation',
            'content_hash' => hash('sha256', 'prior observation'),
            'relevance' => 'medium',
            'timestamp' => '2026-07-26 11:00',
            'token_count' => OmTokenEstimator::estimate('prior observation'),
            'observer_model' => 'llama_cpp_test/test',
            'observer_schema_version' => '1',
            'created_at' => '2026-07-26T11:00:00+00:00',
        ]);
        $connection->insert('om_generation_retained_observation', [
            'generation_id' => $priorGen,
            'observation_id' => 'obs-prior',
            'position' => 0,
        ]);

        $setHash = OmIdentity::observationSetHash('run-r', ['obs-prior', $obsId]);
        $generationId = OmIdentity::thresholdGenerationId('run-r', $priorGen, $setHash, 'llama_cpp_test/test', '1');

        $agentCalls = 0;
        $seen = [];
        $api = $this->api($settings, static function (AgentCallRequestDTO $request) use (&$agentCalls, &$seen, $obsId): void {
            ++$agentCalls;
            $seen[] = [
                'model' => $request->model,
                'maxToolCalls' => $request->maxToolCalls,
                'tool' => $request->tools[0]->name ?? null,
            ];
            $tool = $request->tools[0] ?? null;
            if (null === $tool) {
                throw new \RuntimeException('expected tool');
            }
            if ('record_reflections' === $tool->name) {
                ($tool->handler)([
                    'reflections' => [[
                        'content' => 'User requires feature-flag rollouts for risky releases',
                        'supporting_observation_ids' => [$obsId],
                    ]],
                ]);

                return;
            }
            if ('drop_observations' === $tool->name) {
                throw new \RuntimeException('Dropper must not run when observation pool is under max tokens');
            }
            throw new \RuntimeException('unexpected tool '.$tool->name);
        });

        $handler = new ReflectGenerationJobHandler(new NullLogger());
        $payload = [
            'run_id' => 'run-r',
            'generation_id' => $generationId,
            'threshold_idempotency_key' => $generationId,
            'observation_set_hash' => $setHash,
            'prior_active_generation_id' => $priorGen,
            'reflector_model' => 'llama_cpp_test/test',
            'reflector_schema_version' => '1',
        ];
        $handler->handle($api, $payload, 'job-r', 'run-r');

        $this->assertSame(1, $agentCalls, 'only Reflector model call when pool under max');
        $this->assertSame('llama_cpp_test/test', $seen[0]['model']);
        $this->assertSame(16, $seen[0]['maxToolCalls']);
        $this->assertSame('record_reflections', $seen[0]['tool']);

        $active = (string) $connection->fetchOne(
            'SELECT generation_id FROM om_active_generation WHERE run_id = ?',
            ['run-r'],
        );
        $this->assertSame($generationId, $active);

        $reflectionIds = $connection->fetchFirstColumn(
            'SELECT reflection_id FROM om_generation_reflection WHERE generation_id = ? ORDER BY position',
            [$generationId],
        );
        $this->assertCount(2, $reflectionIds);
        $this->assertContains($priorId, $reflectionIds);
        $newId = OmIdentity::reflectionId(
            'run-r',
            '1',
            'User requires feature-flag rollouts for risky releases',
            [$obsId],
        );
        $this->assertContains($newId, $reflectionIds);

        $retained = $connection->fetchFirstColumn(
            'SELECT observation_id FROM om_generation_retained_observation WHERE generation_id = ? ORDER BY position',
            [$generationId],
        );
        $this->assertEqualsCanonicalizing(['obs-prior', $obsId], $retained);

        $handler->handle($api, $payload, 'job-r-2', 'run-r');
        $this->assertSame(1, $agentCalls, 'succeeded generation redelivery must not re-run model');
    }

    public function testDropperRunsAfterNewReflectionWhenPoolOverMaxAndDropsProposedOnly(): void
    {
        $settings = OmSettings::fromArray([
            'model' => 'llama_cpp_test/test',
            'reflector' => ['reflect_after_observation_tokens' => 1],
            'pools' => ['observations_max_tokens' => 50],
        ]);
        $paths = \Ineersa\HatfieldExt\ObservationalMemory\Runtime\OmPaths::fromSettings($settings, $this->projectDir);
        $connection = $this->omDatabaseFactory()->connectAndMigrate($paths->databasePath, new NullLogger());

        $obsA = $this->insertObs($connection, 'run-d', 'obs-a', 'low', '2020-01-01 00:00', str_repeat('a', 160), 1);
        $obsB = $this->insertObs($connection, 'run-d', 'obs-b', 'critical', '2026-01-01 00:00', str_repeat('b', 160), 2);

        $setHash = OmIdentity::observationSetHash('run-d', [$obsA, $obsB]);
        $generationId = OmIdentity::thresholdGenerationId('run-d', null, $setHash, 'llama_cpp_test/test', '1');

        $agentCalls = 0;
        $stages = [];
        $api = $this->api($settings, static function (AgentCallRequestDTO $request) use (&$agentCalls, &$stages, $obsA, $obsB, $connection): void {
            ++$agentCalls;
            $activity = (new \Ineersa\HatfieldExt\ObservationalMemory\Storage\ActivityRepository($connection))->findFresh('run-d');
            $stages[] = [
                'tool' => $request->tools[0]->name ?? null,
                'stage' => $activity['stage'] ?? null,
                'current_tokens' => $activity['current_tokens'] ?? null,
                'target_tokens' => $activity['target_tokens'] ?? null,
            ];
            if ('llama_cpp_test/test' !== $request->model) {
                throw new \RuntimeException('expected shared model');
            }
            if (16 !== $request->maxToolCalls) {
                throw new \RuntimeException('expected maxToolCalls=16');
            }
            $tool = $request->tools[0] ?? null;
            if (null === $tool) {
                throw new \RuntimeException('expected tool');
            }
            if ('record_reflections' === $tool->name) {
                ($tool->handler)([
                    'reflections' => [[
                        'content' => 'User deploys with feature flags',
                        'supporting_observation_ids' => [$obsA],
                    ]],
                ]);

                return;
            }
            if ('drop_observations' === $tool->name) {
                // Propose both; server ranking+cap decides.
                ($tool->handler)(['ids' => [$obsB, $obsA]]);

                return;
            }
            throw new \RuntimeException('unexpected tool '.$tool->name);
        });

        $handler = new ReflectGenerationJobHandler(new NullLogger());
        $handler->handle($api, [
            'run_id' => 'run-d',
            'generation_id' => $generationId,
            'threshold_idempotency_key' => $generationId,
            'observation_set_hash' => $setHash,
            'prior_active_generation_id' => null,
            'reflector_model' => 'llama_cpp_test/test',
            'reflector_schema_version' => '1',
        ], 'job-d', 'run-d');

        $this->assertSame(2, $agentCalls, 'Reflector then Dropper');
        $this->assertSame('reflector', $stages[0]['stage'] ?? null);
        $this->assertSame('dropper', $stages[1]['stage'] ?? null);
        $this->assertSame(50, $stages[1]['target_tokens'] ?? null);
        $this->assertIsInt($stages[0]['current_tokens'] ?? null);
        $this->assertGreaterThan(0, $stages[0]['current_tokens']);
        $this->assertNull(
            (new \Ineersa\HatfieldExt\ObservationalMemory\Storage\ActivityRepository($connection))->findFresh('run-d'),
            'reflect/dropper finally must clear activity',
        );
        $retained = $connection->fetchFirstColumn(
            'SELECT observation_id FROM om_generation_retained_observation WHERE generation_id = ?',
            [$generationId],
        );
        // Covered low obs should drop before uncovered critical when proposed.
        $this->assertContains($obsB, $retained);
        $this->assertNotContains($obsA, $retained);
    }

    public function testZeroNewReflectionsSkipsDropperAndRetainsAllObservations(): void
    {
        $settings = OmSettings::fromArray([
            'model' => 'llama_cpp_test/test',
            'reflector' => ['reflect_after_observation_tokens' => 1],
            'pools' => ['observations_max_tokens' => 10],
        ]);
        $paths = \Ineersa\HatfieldExt\ObservationalMemory\Runtime\OmPaths::fromSettings($settings, $this->projectDir);
        $connection = $this->omDatabaseFactory()->connectAndMigrate($paths->databasePath, new NullLogger());

        $content = str_repeat('x', 200);
        $obsId = OmIdentity::observationId('run-z', '1', '2026-07-26 12:00', $content, [['run_id' => 'run-z', 'seq' => 1]]);
        $connection->insert('om_observation', [
            'observation_id' => $obsId,
            'run_id' => 'run-z',
            'boundary_key' => 'b1',
            'source_start_seq' => 1,
            'source_end_seq' => 1,
            'source_refs_json' => '[{"run_id":"run-z","seq":1}]',
            'content' => $content,
            'content_hash' => hash('sha256', $content),
            'relevance' => 'low',
            'timestamp' => '2026-07-26 12:00',
            'token_count' => OmTokenEstimator::estimate($content),
            'observer_model' => 'llama_cpp_test/test',
            'observer_schema_version' => '1',
            'created_at' => '2026-07-26T12:00:00+00:00',
        ]);

        $setHash = OmIdentity::observationSetHash('run-z', [$obsId]);
        $generationId = OmIdentity::thresholdGenerationId('run-z', null, $setHash, 'llama_cpp_test/test', '1');

        $agentCalls = 0;
        $api = $this->api($settings, static function (AgentCallRequestDTO $request) use (&$agentCalls): void {
            ++$agentCalls;
            $tool = $request->tools[0] ?? null;
            if (null === $tool || 'record_reflections' !== $tool->name) {
                throw new \RuntimeException('Dropper must not run when Reflector emits zero new reflections');
            }
            // Zero new reflections: no tool call mutation.
        });

        $handler = new ReflectGenerationJobHandler(new NullLogger());
        $handler->handle($api, [
            'run_id' => 'run-z',
            'generation_id' => $generationId,
            'threshold_idempotency_key' => $generationId,
            'observation_set_hash' => $setHash,
            'prior_active_generation_id' => null,
            'reflector_model' => 'llama_cpp_test/test',
            'reflector_schema_version' => '1',
        ], 'job-z', 'run-z');

        $this->assertSame(1, $agentCalls);
        $active = (string) $connection->fetchOne(
            'SELECT generation_id FROM om_active_generation WHERE run_id = ?',
            ['run-z'],
        );
        $this->assertSame($generationId, $active);
        $retained = $connection->fetchFirstColumn(
            'SELECT observation_id FROM om_generation_retained_observation WHERE generation_id = ?',
            [$generationId],
        );
        $this->assertSame([$obsId], $retained);
        $refCount = (int) $connection->fetchOne(
            'SELECT COUNT(*) FROM om_generation_reflection WHERE generation_id = ?',
            [$generationId],
        );
        $this->assertSame(0, $refCount);
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

            public function registerSkill(string $skillDirectory): void
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
                    'model' => $this->settings->model,
                    'observer' => [
                        'context_window_ratio' => $this->settings->observerContextWindowRatio,
                        'renderer_version' => $this->settings->rendererVersion,
                        'schema_version' => $this->settings->observerSchemaVersion,
                    ],
                    'reflector' => [
                        'reflect_after_observation_tokens' => $this->settings->reflectAfterObservationTokens,
                        'schema_version' => $this->settings->reflectorSchemaVersion,
                    ],
                    'pools' => [
                        'observations_max_tokens' => $this->settings->observationsMaxTokens,
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

    private function insertObs(
        \Doctrine\DBAL\Connection $connection,
        string $runId,
        string $logical,
        string $relevance,
        string $timestamp,
        string $content,
        int $seq,
    ): string {
        $obsId = OmIdentity::observationId($runId, '1', $timestamp, $content, [['run_id' => $runId, 'seq' => $seq]]);
        $connection->insert('om_observation', [
            'observation_id' => $obsId,
            'run_id' => $runId,
            'boundary_key' => 'b'.$seq,
            'source_start_seq' => $seq,
            'source_end_seq' => $seq,
            'source_refs_json' => \sprintf('[{"run_id":"%s","seq":%d}]', $runId, $seq),
            'content' => $content,
            'content_hash' => hash('sha256', $content),
            'relevance' => $relevance,
            'timestamp' => $timestamp,
            'token_count' => OmTokenEstimator::estimate($content),
            'observer_model' => 'llama_cpp_test/test',
            'observer_schema_version' => '1',
            'created_at' => '2026-07-26T12:00:00+00:00',
        ]);

        return $obsId;
    }
}
