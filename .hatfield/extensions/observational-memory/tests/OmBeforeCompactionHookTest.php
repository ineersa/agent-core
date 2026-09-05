<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\ObservationalMemory\Tests;

use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;
use Ineersa\Hatfield\ExtensionApi\Agent\AgentRunnerInterface;
use Ineersa\Hatfield\ExtensionApi\Agent\ExtensionAgentJobHandlerInterface;
use Ineersa\Hatfield\ExtensionApi\Agent\ExtensionAgentJobRequestDTO;
use Ineersa\Hatfield\ExtensionApi\Command\CommandDefinitionDTO;
use Ineersa\Hatfield\ExtensionApi\Command\ExtensionCommandHandlerInterface;
use Ineersa\Hatfield\ExtensionApi\Compaction\BeforeCompactionHookContextDTO;
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
use Ineersa\HatfieldExt\ObservationalMemory\Compaction\ActiveMemoryRenderer;
use Ineersa\HatfieldExt\ObservationalMemory\Compaction\OmBeforeCompactionHook;
use Ineersa\HatfieldExt\ObservationalMemory\Runtime\OmSettings;
use Ineersa\HatfieldExt\ObservationalMemory\Storage\MemoryGenerationRepository;
use Ineersa\HatfieldExt\ObservationalMemory\Storage\ObservationRepository;
use Ineersa\HatfieldExt\ObservationalMemory\Tests\Support\OmDatabaseFactoryTestService;
use Psr\Log\NullLogger;

/**
 * Thesis: CompactRun hook is Pi-style instant durable-memory projection.
 * Non-empty active memory replaces immediately; empty continues; no dispatch/model/event reads.
 */
final class OmBeforeCompactionHookTest extends IsolatedKernelTestCase
{
    private string $projectDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->projectDir = TestDirectoryIsolation::createProjectTempDir('om-before-compact');
        TestDirectoryIsolation::createHatfieldTree($this->projectDir);
    }

    protected function tearDown(): void
    {
        TestDirectoryIsolation::removeDirectory($this->projectDir);
        parent::tearDown();
    }

    public function testProjectsActiveMemoryWithoutDispatchModelOrEventReads(): void
    {
        $dbPath = $this->projectDir.'/.hatfield/extensions-data/observational-memory/om.sqlite';
        $connection = $this->omDatabaseFactory()->connectAndMigrate($dbPath);
        $obs = new ObservationRepository($connection);
        $gen = new MemoryGenerationRepository($connection);

        $obsIdRetained = str_repeat('a', 64);
        $obsIdPost = str_repeat('b', 64);
        $refId = str_repeat('c', 64);

        $obs->commitChunkPartCoverage(
            coverageKey: 'cov-pre',
            runId: 'run-1',
            boundaryKey: 'b1',
            sourceStartSeq: 1,
            sourceEndSeq: 3,
            chunkKey: 'chunk-1',
            partIndex: 1,
            partCount: 1,
            sourceDigest: 'digest-pre',
            partDigest: 'part-pre',
            rendererVersion: '1',
            observerSchemaVersion: '1',
            observerModel: 'llama_cpp_test/test',
            observations: [[
                'observation_id' => $obsIdRetained,
                'content' => 'Retained after generation',
                'content_hash' => hash('sha256', 'Retained after generation'),
                'relevance' => 'high',
                'timestamp' => '2026-07-29 10:00',
                'token_count' => 8,
                'source_refs_json' => json_encode([['run_id' => 'run-1', 'seq' => 2]], \JSON_THROW_ON_ERROR),
            ]],
            coveredAt: '2026-07-29T10:00:00+00:00',
        );

        $gen->claimGeneration(
            generationId: 'gen-1',
            runId: 'run-1',
            triggerKind: MemoryGenerationRepository::TRIGGER_THRESHOLD,
            observationSetHash: hash('sha256', 'set-1'),
            reflectorModel: 'llama_cpp_test/test',
            reflectorSchemaVersion: '1',
            now: '2026-07-29T10:01:00+00:00',
            requiredStartSeq: 1,
            requiredEndSeq: 3,
        );
        $gen->commitSucceededGeneration(
            generationId: 'gen-1',
            runId: 'run-1',
            observationSetHash: hash('sha256', 'set-1'),
            reflectorModel: 'llama_cpp_test/test',
            reflectorSchemaVersion: '1',
            reflections: [[
                'reflection_id' => $refId,
                'content' => 'Stable reflection fact',
                'supporting_observation_ids_json' => json_encode([$obsIdRetained], \JSON_THROW_ON_ERROR),
                'token_count' => 6,
            ]],
            retainedObservationIds: [$obsIdRetained],
            now: '2026-07-29T10:01:01+00:00',
        );

        $obs->commitChunkPartCoverage(
            coverageKey: 'cov-post',
            runId: 'run-1',
            boundaryKey: 'b2',
            sourceStartSeq: 4,
            sourceEndSeq: 5,
            chunkKey: 'chunk-2',
            partIndex: 1,
            partCount: 1,
            sourceDigest: 'digest-post',
            partDigest: 'part-post',
            rendererVersion: '1',
            observerSchemaVersion: '1',
            observerModel: 'llama_cpp_test/test',
            observations: [[
                'observation_id' => $obsIdPost,
                'content' => 'Post-generation observation',
                'content_hash' => hash('sha256', 'Post-generation observation'),
                'relevance' => 'medium',
                'timestamp' => '2026-07-29 10:02',
                'token_count' => 7,
                'source_refs_json' => json_encode([['run_id' => 'run-1', 'seq' => 5]], \JSON_THROW_ON_ERROR),
            ]],
            coveredAt: '2026-07-29T10:02:00+00:00',
        );

        $settings = OmSettings::fromArray([
            'model' => 'llama_cpp_test/test',
            'observer' => [],
            'reflector' => [],
        ]);
        $api = $this->failOnDispatchModelOrEvents($this->projectDir, $settings);
        $hook = new OmBeforeCompactionHook($api, $settings, new NullLogger());

        $result = $hook->beforeCompaction(new BeforeCompactionHookContextDTO(
            runId: 'run-1',
            turnNo: 1,
            trigger: 'manual',
            requiredStartSeq: 1,
            requiredEndSeq: 5,
            tokenEstimateBefore: 1000,
            messagesCompacted: 10,
            messagesRetained: 5,
            firstRetainedIndex: 10,
            priorSummaryPresent: false,
            customInstructions: null,
            resolvedModel: 'llama_cpp_test/test',
            thinkingLevel: null,
        ));

        $this->assertFalse($result->cancels());
        $this->assertNotNull($result->replacementSummary);
        $expected = ActiveMemoryRenderer::render(
            [
                ['reflection_id' => $refId, 'content' => 'Stable reflection fact', 'position' => 0],
            ],
            [
                [
                    'observation_id' => $obsIdRetained,
                    'content' => 'Retained after generation',
                    'relevance' => 'high',
                    'timestamp' => '2026-07-29 10:00',
                ],
                [
                    'observation_id' => $obsIdPost,
                    'content' => 'Post-generation observation',
                    'relevance' => 'medium',
                    'timestamp' => '2026-07-29 10:02',
                ],
            ],
        );
        $this->assertSame($expected, $result->replacementSummary);
        $this->assertSame('observational_memory', $result->metadata['om_source'] ?? null);
        $this->assertSame('active_durable_memory', $result->metadata['om_projection'] ?? null);

        // No compaction request/result rows are created on the Pi path.
        $requestCount = (int) $connection->fetchOne('SELECT COUNT(1) FROM om_compaction_request');
        $resultCount = (int) $connection->fetchOne('SELECT COUNT(1) FROM om_compaction_result');
        $this->assertSame(0, $requestCount);
        $this->assertSame(0, $resultCount);
    }

    public function testPreGenerationObservationsRenderAndEmptyContinues(): void
    {
        $dbPath = $this->projectDir.'/.hatfield/extensions-data/observational-memory/om.sqlite';
        $connection = $this->omDatabaseFactory()->connectAndMigrate($dbPath);
        $obs = new ObservationRepository($connection);
        $obsId = str_repeat('d', 64);

        $obs->commitChunkPartCoverage(
            coverageKey: 'cov-only',
            runId: 'run-2',
            boundaryKey: 'b1',
            sourceStartSeq: 1,
            sourceEndSeq: 2,
            chunkKey: 'chunk-1',
            partIndex: 1,
            partCount: 1,
            sourceDigest: 'digest-only',
            partDigest: 'part-only',
            rendererVersion: '1',
            observerSchemaVersion: '1',
            observerModel: 'llama_cpp_test/test',
            observations: [[
                'observation_id' => $obsId,
                'content' => 'Only observation before generation',
                'content_hash' => hash('sha256', 'Only observation before generation'),
                'relevance' => 'low',
                'timestamp' => '2026-07-29 11:00',
                'token_count' => 5,
                'source_refs_json' => json_encode([['run_id' => 'run-2', 'seq' => 1]], \JSON_THROW_ON_ERROR),
            ]],
            coveredAt: '2026-07-29T11:00:00+00:00',
        );

        $settings = OmSettings::fromArray([
            'model' => 'llama_cpp_test/test',
            'observer' => [],
            'reflector' => [],
        ]);
        $api = $this->failOnDispatchModelOrEvents($this->projectDir, $settings);
        $hook = new OmBeforeCompactionHook($api, $settings, new NullLogger());

        $withMemory = $hook->beforeCompaction(new BeforeCompactionHookContextDTO(
            runId: 'run-2',
            turnNo: 1,
            trigger: 'manual',
            requiredStartSeq: 1,
            requiredEndSeq: 2,
            tokenEstimateBefore: 100,
            messagesCompacted: 2,
            messagesRetained: 1,
            firstRetainedIndex: 2,
            priorSummaryPresent: false,
            customInstructions: null,
            resolvedModel: 'llama_cpp_test/test',
            thinkingLevel: null,
        ));
        $this->assertFalse($withMemory->cancels());
        $this->assertStringContainsString('Only observation before generation', (string) $withMemory->replacementSummary);
        $this->assertStringContainsString('[dddddddddddd]', (string) $withMemory->replacementSummary);

        $empty = $hook->beforeCompaction(new BeforeCompactionHookContextDTO(
            runId: 'run-empty',
            turnNo: 1,
            trigger: 'manual',
            requiredStartSeq: 1,
            requiredEndSeq: 0,
            tokenEstimateBefore: 10,
            messagesCompacted: 0,
            messagesRetained: 0,
            firstRetainedIndex: 0,
            priorSummaryPresent: false,
            customInstructions: null,
            resolvedModel: 'llama_cpp_test/test',
            thinkingLevel: null,
        ));
        $this->assertFalse($empty->cancels());
        $this->assertNull($empty->replacementSummary);
        $this->assertFalse($empty->hasReplacementSummary());
    }

    private function omDatabaseFactory(): OmDatabaseFactoryTestService
    {
        /** @var OmDatabaseFactoryTestService $service */
        $service = self::getContainer()->get('test.om_database_factory');

        return $service;
    }

    private function failOnDispatchModelOrEvents(string $cwd, OmSettings $settings): ExtensionApiInterface
    {
        return new class($cwd, $settings) implements ExtensionApiInterface {
            public function __construct(
                private readonly string $cwd,
                private readonly OmSettings $settings,
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
                    'model' => $this->settings->model,
                    'observer' => [
                        'schema_version' => $this->settings->observerSchemaVersion,
                        'renderer_version' => $this->settings->rendererVersion,
                        'context_window_ratio' => $this->settings->observerContextWindowRatio,
                    ],
                    'reflector' => [
                        'schema_version' => $this->settings->reflectorSchemaVersion,
                        'reflect_after_observation_tokens' => $this->settings->reflectAfterObservationTokens,
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

            public function registerCommand(CommandDefinitionDTO $definition, ExtensionCommandHandlerInterface $handler): void
            {
            }

            public function registerAfterTurnCommitHook(AfterTurnCommitHookInterface $hook): void
            {
            }

            public function registerSessionStartHook(\Ineersa\Hatfield\ExtensionApi\Lifecycle\AfterSessionStartHookInterface $hook): void
            {
            }

            public function registerBeforeCompactionHook(BeforeCompactionHookInterface $hook): void
            {
            }

            public function registerPromptContributor(PromptContributorInterface $contributor): void
            {
            }

            public function registerSkill(string $skillDirectory): void
            {
            }

            public function registerToolCallRewriteHook(string $toolName, ToolCallRewriteHookInterface $hook): void
            {
            }

            public function registerExtensionAgentJobHandler(string $handlerId, ExtensionAgentJobHandlerInterface $handler): void
            {
            }

            public function dispatchExtensionAgentJob(ExtensionAgentJobRequestDTO $request): void
            {
                throw new \LogicException('Pi-style compaction must not dispatch extension_agent jobs.');
            }

            public function agent(): AgentRunnerInterface
            {
                throw new \LogicException('Pi-style compaction must not invoke AgentRunner.');
            }

            public function sessionEvents(): SessionEventReaderInterface
            {
                return new class implements SessionEventReaderInterface {
                    public function readRange(string $runId, int $startSeq, int $endSeq): iterable
                    {
                        throw new \LogicException('Pi-style compaction must not read session events.');
                    }
                };
            }

            public function exec(): ExecInterface
            {
                throw new \LogicException('unused');
            }
        };
    }
}
