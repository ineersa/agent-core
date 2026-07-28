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
use Ineersa\HatfieldExt\ObservationalMemory\Query\OmQueryService;
use Ineersa\HatfieldExt\ObservationalMemory\Runtime\OmSettings;
use Ineersa\HatfieldExt\ObservationalMemory\Storage\CompactionRepository;
use Ineersa\HatfieldExt\ObservationalMemory\Storage\MemoryGenerationRepository;
use Ineersa\HatfieldExt\ObservationalMemory\Storage\ObservationRepository;
use Ineersa\HatfieldExt\ObservationalMemory\Tests\Support\OmDatabaseFactoryTestService;
use PHPUnit\Framework\Attributes\Test;

/**
 * Thesis: /om-status and /om-view read current-run OM SQLite only with privacy-safe aggregates;
 * another run never leaks; empty state is explicit.
 */
final class OmQueryServiceTest extends IsolatedKernelTestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpDir = TestDirectoryIsolation::createProjectTempDir('om-query');
    }

    protected function tearDown(): void
    {
        TestDirectoryIsolation::removeDirectory($this->tmpDir);
        parent::tearDown();
    }

    #[Test]
    public function statusAndViewAreSessionScopedAndPrivacySafe(): void
    {
        $dbPath = $this->tmpDir.'/om.sqlite';
        $connection = $this->omDatabaseFactory()->connectAndMigrate($dbPath);
        $obs = new ObservationRepository($connection);
        $gen = new MemoryGenerationRepository($connection);
        $comp = new CompactionRepository($connection);

        $obsIdA = str_repeat('a', 64);
        $obsIdB = str_repeat('b', 64);
        $refId = str_repeat('c', 64);

        $obs->commitChunkPartCoverage(
            coverageKey: 'cov-run-a',
            runId: 'run-a',
            boundaryKey: 'b1',
            sourceStartSeq: 1,
            sourceEndSeq: 3,
            chunkKey: 'chunk-1',
            partIndex: 1,
            partCount: 1,
            sourceDigest: 'digest-a',
            partDigest: 'part-a',
            rendererVersion: '1',
            observerSchemaVersion: '1',
            observerModel: 'llama_cpp_test/test',
            observations: [[
                'observation_id' => $obsIdA,
                'content' => 'User prefers hyphenated OM commands',
                'content_hash' => hash('sha256', 'User prefers hyphenated OM commands'),
                'relevance' => 'high',
                'timestamp' => '2026-07-28 12:00',
                'token_count' => 12,
                'source_refs_json' => json_encode([['run_id' => 'run-a', 'seq' => 2]], \JSON_THROW_ON_ERROR),
            ]],
            coveredAt: '2026-07-28T12:00:00+00:00',
        );

        // Other run must not appear in run-a status/view.
        $obs->commitChunkPartCoverage(
            coverageKey: 'cov-run-b',
            runId: 'run-b',
            boundaryKey: 'b1',
            sourceStartSeq: 1,
            sourceEndSeq: 1,
            chunkKey: 'chunk-b',
            partIndex: 1,
            partCount: 1,
            sourceDigest: 'digest-b',
            partDigest: 'part-b',
            rendererVersion: '1',
            observerSchemaVersion: '1',
            observerModel: 'llama_cpp_test/test',
            observations: [[
                'observation_id' => $obsIdB,
                'content' => 'SECRET_OTHER_RUN_CONTENT',
                'content_hash' => hash('sha256', 'SECRET_OTHER_RUN_CONTENT'),
                'relevance' => 'critical',
                'timestamp' => '2026-07-28 12:01',
                'token_count' => 9,
                'source_refs_json' => json_encode([['run_id' => 'run-b', 'seq' => 1]], \JSON_THROW_ON_ERROR),
            ]],
            coveredAt: '2026-07-28T12:01:00+00:00',
        );

        $gen->claimGeneration(
            generationId: 'gen-a',
            runId: 'run-a',
            triggerKind: MemoryGenerationRepository::TRIGGER_THRESHOLD,
            observationSetHash: hash('sha256', 'set-a'),
            reflectorModel: 'llama_cpp_test/test',
            reflectorSchemaVersion: '1',
            now: '2026-07-28T12:02:00+00:00',
            requiredStartSeq: 1,
            requiredEndSeq: 3,
        );
        $gen->commitSucceededGeneration(
            generationId: 'gen-a',
            runId: 'run-a',
            observationSetHash: hash('sha256', 'set-a'),
            reflectorModel: 'llama_cpp_test/test',
            reflectorSchemaVersion: '1',
            reflections: [[
                'reflection_id' => $refId,
                'content' => 'Commands are hyphenated',
                'supporting_observation_ids_json' => json_encode([$obsIdA], \JSON_THROW_ON_ERROR),
                'token_count' => 8,
            ]],
            retainedObservationIds: [$obsIdA],
            now: '2026-07-28T12:02:01+00:00',
        );

        $comp->ensureRequest(
            requestId: 'req-a',
            runId: 'run-a',
            requiredStartSeq: 1,
            requiredEndSeq: 3,
            requiredWatermark: 3,
            requestFingerprint: hash('sha256', 'fp-a'),
            now: '2026-07-28T12:03:00+00:00',
        );

        $settings = OmSettings::fromArray([
            'storage' => ['database' => $dbPath],
            'observer' => ['model' => 'llama_cpp_test/test'],
            'reflector' => ['model' => 'llama_cpp_test/test'],
        ]);
        $api = new class($this->tmpDir) implements ExtensionApiInterface {
            public function __construct(private string $cwd)
            {
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
                return new class implements SessionEventReaderInterface {
                    public function readRange(string $runId, int $startSeq, int $endSeq): iterable
                    {
                        return [];
                    }
                };
            }

            public function exec(): ExecInterface
            {
                throw new \LogicException('unused');
            }
        };

        $service = new OmQueryService($api, $settings);

        $status = $service->formatStatus('run-a');
        $this->assertStringContainsString('Hatfield-managed single FIFO extension_agent', $status);
        $this->assertStringContainsString('max_retries: 1', $status);
        $this->assertStringContainsString('failure_transport: none', $status);
        $this->assertStringContainsString('covered_through_seq: 3', $status);
        $this->assertStringContainsString('generation_id: gen-a', $status);
        $this->assertStringContainsString('queued: 1', $status);
        $this->assertStringNotContainsString('SECRET_OTHER_RUN_CONTENT', $status);
        $this->assertStringNotContainsString('api_key', $status);
        $this->assertStringNotContainsString('provider credential', $status);

        $view = $service->formatView('run-a');
        $this->assertStringContainsString($refId, $view);
        $this->assertStringContainsString($obsIdA, $view);
        $this->assertStringContainsString('(run-a,2)', $view);
        $this->assertStringNotContainsString($obsIdB, $view);
        $this->assertStringNotContainsString('SECRET_OTHER_RUN_CONTENT', $view);

        $empty = $service->formatView('run-empty');
        $this->assertStringContainsString('Active generation: none', $empty);
        $this->assertStringContainsString('(none)', $empty);
    }

    private function omDatabaseFactory(): OmDatabaseFactoryTestService
    {
        /** @var OmDatabaseFactoryTestService $service */
        $service = self::getContainer()->get('test.om_database_factory');

        return $service;
    }
}
