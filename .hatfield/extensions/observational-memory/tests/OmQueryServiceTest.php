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
use PHPUnit\Framework\Attributes\Test;

/**
 * Thesis: human /om-status and /om-view read current-run OM SQLite only;
 * another run never leaks; empty state is explicit; short IDs are displayed.
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
    public function statusAndViewAreSessionScopedAndHumanFormatted(): void
    {
        $dbPath = $this->tmpDir.'/om.sqlite';
        $connection = $this->omDatabaseFactory()->connectAndMigrate($dbPath);
        $obs = new ObservationRepository($connection);
        $gen = new MemoryGenerationRepository($connection);

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
                'source_refs_json' => json_encode([
                    ['run_id' => 'run-a', 'seq' => 2],
                    ['run_id' => 'run-b', 'seq' => 1],
                ], \JSON_THROW_ON_ERROR),
            ]],
            coveredAt: '2026-07-28T12:00:00+00:00',
        );

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
                'supporting_observation_ids_json' => json_encode([$obsIdA, $obsIdB], \JSON_THROW_ON_ERROR),
                'token_count' => 8,
            ]],
            retainedObservationIds: [$obsIdA],
            now: '2026-07-28T12:02:01+00:00',
        );

        $settings = OmSettings::fromArray([
            'storage' => ['database' => $dbPath],
            'model' => 'llama_cpp_test/test',
            'observer' => [],
            'reflector' => [
                'reflect_after_observation_tokens' => 40000,
            ],
            'pools' => [
                'observations_max_tokens' => 30000,
            ],
        ]);
        $api = $this->api($this->tmpDir);
        $service = new OmQueryService($api, $settings);

        $status = $service->formatStatus('run-a');
        $this->assertStringContainsString('## Observational memory', $status);
        $this->assertStringContainsString('### Memory', $status);
        $this->assertStringContainsString('- **Observations:** 1 recorded / 0 dropped / 1 active / 1 visible', $status);
        $this->assertStringContainsString('- **Reflections:** 1 recorded / 1 visible', $status);
        $this->assertStringContainsString('- **Coverage:** through event 3', $status);
        $this->assertStringContainsString('- **Next reflection:** ~15 / 40,000 tokens (0%)', $status);
        $this->assertStringContainsString('- **Active observation pool:** ~15 / 30,000 max tokens (0%)', $status);
        $this->assertStringContainsString('- **Pipeline:** Observer → delta Reflector → bounded Dropper (async FIFO)', $status);
        $this->assertStringContainsString('- **Compaction:** instant projection of current durable memory (no model wait)', $status);
        $this->assertStringContainsString('> Durable memory state only; worker and queue liveness are not tracked here.', $status);
        $this->assertStringNotContainsString('max_retries', $status);
        $this->assertStringNotContainsString('extension_agent', $status);
        $this->assertStringNotContainsString('SECRET_OTHER_RUN_CONTENT', $status);

        $view = $service->formatView('run-a');
        $this->assertStringContainsString('## Reflections', $view);
        $this->assertStringContainsString('## Observations', $view);
        $this->assertStringContainsString('`[cccccccccccc]`', $view);
        $this->assertStringContainsString('`[aaaaaaaaaaaa]`', $view);
        $this->assertStringContainsString('**[high]**', $view);
        $this->assertStringContainsString('> Sources: event `2`', $view);
        $this->assertStringContainsString('> Supports observations `[aaaaaaaaaaaa]`', $view);
        $this->assertStringNotContainsString($obsIdA, $view); // full 64-char id must not appear
        $this->assertStringNotContainsString($obsIdB, $view);
        $this->assertStringNotContainsString('SECRET_OTHER_RUN_CONTENT', $view);
        $this->assertStringNotContainsString('run-b', $view);

        $empty = $service->formatView('run-empty');
        $this->assertStringContainsString('*No reflections yet.*', $empty);
        $this->assertStringContainsString('*No observations yet.*', $empty);

        $emptyStatus = $service->formatStatus('run-empty');
        $this->assertStringContainsString('no events covered yet', $emptyStatus);
        $this->assertStringContainsString('**Compaction:** instant projection of current durable memory (no model wait)', $emptyStatus);
    }

    #[Test]
    public function recallAcceptsUniquePrefixAndRejectsAmbiguousOrMissing(): void
    {
        $dbPath = $this->tmpDir.'/om-recall.sqlite';
        $connection = $this->omDatabaseFactory()->connectAndMigrate($dbPath);
        $obs = new ObservationRepository($connection);

        // Unique at 14 chars; share first 13 so a 13-char recall is ambiguous.
        $id1 = 'aaaaaaaaaaaaa1'.str_repeat('1', 50);
        $id2 = 'aaaaaaaaaaaaa2'.str_repeat('2', 50);
        $obs->commitChunkPartCoverage(
            coverageKey: 'cov-prefix',
            runId: 'run-prefix',
            boundaryKey: 'b1',
            sourceStartSeq: 1,
            sourceEndSeq: 2,
            chunkKey: 'chunk-1',
            partIndex: 1,
            partCount: 1,
            sourceDigest: 'd1',
            partDigest: 'p1',
            rendererVersion: '1',
            observerSchemaVersion: '1',
            observerModel: 'llama_cpp_test/test',
            observations: [
                [
                    'observation_id' => $id1,
                    'content' => 'first',
                    'content_hash' => hash('sha256', 'first'),
                    'relevance' => 'medium',
                    'timestamp' => '2026-07-28 12:00',
                    'token_count' => 1,
                    'source_refs_json' => json_encode([['run_id' => 'run-prefix', 'seq' => 1]], \JSON_THROW_ON_ERROR),
                ],
                [
                    'observation_id' => $id2,
                    'content' => 'second',
                    'content_hash' => hash('sha256', 'second'),
                    'relevance' => 'low',
                    'timestamp' => '2026-07-28 12:01',
                    'token_count' => 1,
                    'source_refs_json' => json_encode([['run_id' => 'run-prefix', 'seq' => 2]], \JSON_THROW_ON_ERROR),
                ],
            ],
            coveredAt: '2026-07-28T12:00:00+00:00',
        );

        $service = new OmQueryService(
            $this->api($this->tmpDir),
            OmSettings::fromArray([
                'storage' => ['database' => $dbPath],
                'model' => 'llama_cpp_test/test',
                'observer' => [],
                'reflector' => [],
            ]),
        );

        $unique = $service->recall('run-prefix', substr($id1, 0, 14));
        $this->assertTrue($unique['ok']);
        $this->assertSame('observation', $unique['kind']);
        $this->assertSame($id1, $unique['id']);

        $ambiguous = $service->recall('run-prefix', substr($id1, 0, 13));
        $this->assertFalse($ambiguous['ok']);
        $this->assertSame('ambiguous_id', $ambiguous['error']);

        $missing = $service->recall('run-prefix', str_repeat('f', 12));
        $this->assertFalse($missing['ok']);
        $this->assertSame('not_found', $missing['error']);

        $invalid = $service->recall('run-prefix', 'short');
        $this->assertFalse($invalid['ok']);
        $this->assertSame('invalid_id', $invalid['error']);
    }

    #[Test]
    public function searchFindsRetainedMemoriesAcrossSessionsWithBoundsAndDateFilters(): void
    {
        $dbPath = $this->tmpDir.'/om-search.sqlite';
        $connection = $this->omDatabaseFactory()->connectAndMigrate($dbPath);
        $obs = new ObservationRepository($connection);
        $gen = new MemoryGenerationRepository($connection);

        $idA = str_repeat('a', 64);
        $idB = str_repeat('b', 64);
        $idC = str_repeat('c', 64);
        $refId = str_repeat('d', 64);

        $obs->commitChunkPartCoverage(
            coverageKey: 'cov-a',
            runId: 'session-a',
            boundaryKey: 'b1',
            sourceStartSeq: 1,
            sourceEndSeq: 1,
            chunkKey: 'chunk-a',
            partIndex: 1,
            partCount: 1,
            sourceDigest: 'da',
            partDigest: 'pa',
            rendererVersion: '1',
            observerSchemaVersion: '1',
            observerModel: 'llama_cpp_test/test',
            observations: [[
                'observation_id' => $idA,
                'content' => 'Worked Symfony AI PR #2510 MapToolArguments upstream',
                'content_hash' => hash('sha256', 'a'),
                'relevance' => 'high',
                'timestamp' => '2026-09-11 02:23',
                'token_count' => 8,
                'source_refs_json' => json_encode([['run_id' => 'session-a', 'seq' => 1]], \JSON_THROW_ON_ERROR),
            ]],
            coveredAt: '2026-09-11T02:23:00+00:00',
        );
        $obs->commitChunkPartCoverage(
            coverageKey: 'cov-b',
            runId: 'session-b',
            boundaryKey: 'b1',
            sourceStartSeq: 1,
            sourceEndSeq: 1,
            chunkKey: 'chunk-b',
            partIndex: 1,
            partCount: 1,
            sourceDigest: 'db',
            partDigest: 'pb',
            rendererVersion: '1',
            observerSchemaVersion: '1',
            observerModel: 'llama_cpp_test/test',
            observations: [[
                'observation_id' => $idB,
                'content' => 'Unrelated note mentioning 2510 clone for ranking',
                'content_hash' => hash('sha256', 'b'),
                'relevance' => 'low',
                'timestamp' => '2026-09-01 10:00',
                'token_count' => 5,
                'source_refs_json' => json_encode([['run_id' => 'session-b', 'seq' => 1]], \JSON_THROW_ON_ERROR),
            ]],
            coveredAt: '2026-09-01T10:00:00+00:00',
        );
        $obs->commitChunkPartCoverage(
            coverageKey: 'cov-c',
            runId: 'session-c',
            boundaryKey: 'b1',
            sourceStartSeq: 1,
            sourceEndSeq: 1,
            chunkKey: 'chunk-c',
            partIndex: 1,
            partCount: 1,
            sourceDigest: 'dc',
            partDigest: 'pc',
            rendererVersion: '1',
            observerSchemaVersion: '1',
            observerModel: 'llama_cpp_test/test',
            observations: [[
                'observation_id' => $idC,
                'content' => 'Literal percent 100% and underscore tool_name should not wildcard-match',
                'content_hash' => hash('sha256', 'c'),
                'relevance' => 'medium',
                'timestamp' => '2026-09-12 12:00',
                'token_count' => 6,
                'source_refs_json' => json_encode([['run_id' => 'session-c', 'seq' => 1]], \JSON_THROW_ON_ERROR),
            ]],
            coveredAt: '2026-09-12T12:00:00+00:00',
        );

        $connection->insert('om_reflection', [
            'reflection_id' => $refId,
            'run_id' => 'session-a',
            'compaction_request_id' => 'req-search',
            'observation_set_hash' => hash('sha256', 'set-search'),
            'content' => 'Durable fact: MapToolArguments landed in Symfony AI PR #2510.',
            'supporting_observation_ids_json' => json_encode([$idA], \JSON_THROW_ON_ERROR),
            'compression_level' => '0',
            'token_count' => 7,
            'reflector_model' => 'llama_cpp_test/test',
            'reflector_schema_version' => '1',
            'created_at' => '2026-09-11T03:00:00+00:00',
        ]);

        $service = new OmQueryService(
            $this->api($this->tmpDir),
            OmSettings::fromArray([
                'storage' => ['database' => $dbPath],
                'model' => 'llama_cpp_test/test',
                'observer' => [],
                'reflector' => [],
            ]),
        );

        $all = $service->search('2510');
        $this->assertTrue($all['ok']);
        $this->assertSame(3, $all['count']);
        $this->assertFalse($all['truncated']);
        $kinds = array_column($all['results'], 'kind');
        $this->assertContains('observation', $kinds);
        $this->assertContains('reflection', $kinds);
        $sessions = array_column($all['results'], 'session_id');
        $this->assertContains('session-a', $sessions);
        $this->assertContains('session-b', $sessions);

        $bounded = $service->search('2510', limit: 1);
        $this->assertTrue($bounded['ok']);
        $this->assertSame(1, $bounded['count']);
        $this->assertTrue($bounded['truncated']);

        $filtered = $service->search('2510', after: '2026-09-10', before: '2026-09-11');
        $this->assertTrue($filtered['ok']);
        $this->assertSame(2, $filtered['count']); // observation 09-11 + reflection 09-11
        foreach ($filtered['results'] as $row) {
            $this->assertSame('session-a', $row['session_id']);
        }

        $escaped = $service->search('100%');
        $this->assertTrue($escaped['ok']);
        $this->assertSame(1, $escaped['count']);
        $this->assertSame($idC, $escaped['results'][0]['id']);

        $underscore = $service->search('tool_name');
        $this->assertTrue($underscore['ok']);
        $this->assertSame(1, $underscore['count']);

        $invalid = $service->search('2510', after: 'yesterday');
        $this->assertFalse($invalid['ok']);
        $this->assertSame('invalid_after', $invalid['error']);
    }

    #[Test]
    public function recallAcceptsExplicitSessionIdAndKeepsOmittedCurrentSessionBehavior(): void
    {
        $dbPath = $this->tmpDir.'/om-recall-session.sqlite';
        $connection = $this->omDatabaseFactory()->connectAndMigrate($dbPath);
        $obs = new ObservationRepository($connection);

        $currentId = str_repeat('1', 64);
        $priorId = str_repeat('2', 64);

        $obs->commitChunkPartCoverage(
            coverageKey: 'cov-current',
            runId: 'run-current',
            boundaryKey: 'b1',
            sourceStartSeq: 1,
            sourceEndSeq: 2,
            chunkKey: 'chunk-current',
            partIndex: 1,
            partCount: 1,
            sourceDigest: 'd1',
            partDigest: 'p1',
            rendererVersion: '1',
            observerSchemaVersion: '1',
            observerModel: 'llama_cpp_test/test',
            observations: [[
                'observation_id' => $currentId,
                'content' => 'current memory',
                'content_hash' => hash('sha256', 'current memory'),
                'relevance' => 'medium',
                'timestamp' => '2026-09-12 10:00',
                'token_count' => 2,
                'source_refs_json' => json_encode([['run_id' => 'run-current', 'seq' => 2]], \JSON_THROW_ON_ERROR),
            ]],
            coveredAt: '2026-09-12T10:00:00+00:00',
        );
        $obs->commitChunkPartCoverage(
            coverageKey: 'cov-prior',
            runId: 'run-prior',
            boundaryKey: 'b1',
            sourceStartSeq: 1,
            sourceEndSeq: 5,
            chunkKey: 'chunk-prior',
            partIndex: 1,
            partCount: 1,
            sourceDigest: 'd2',
            partDigest: 'p2',
            rendererVersion: '1',
            observerSchemaVersion: '1',
            observerModel: 'llama_cpp_test/test',
            observations: [[
                'observation_id' => $priorId,
                'content' => 'prior memory PR #2510',
                'content_hash' => hash('sha256', 'prior memory PR #2510'),
                'relevance' => 'high',
                'timestamp' => '2026-09-11 02:23',
                'token_count' => 4,
                'source_refs_json' => json_encode([['run_id' => 'run-prior', 'seq' => 4]], \JSON_THROW_ON_ERROR),
            ]],
            coveredAt: '2026-09-11T02:23:00+00:00',
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
                        payload: ['text' => $runId.'-'.$seq],
                        createdAt: '2026-09-11T02:23:00+00:00',
                    );
                }
            }
        };

        $service = new OmQueryService(
            $this->apiWithReader($this->tmpDir, $reader),
            OmSettings::fromArray([
                'storage' => ['database' => $dbPath],
                'model' => 'llama_cpp_test/test',
                'observer' => [],
                'reflector' => [],
            ]),
        );

        $missingCross = $service->recall('run-current', substr($priorId, 0, 12));
        $this->assertFalse($missingCross['ok']);
        $this->assertSame('not_found', $missingCross['error']);

        $cross = $service->recall('run-current', substr($priorId, 0, 12), 'run-prior');
        $this->assertTrue($cross['ok']);
        $this->assertSame('observation', $cross['kind']);
        $this->assertSame('run-prior', $cross['session_id']);
        $this->assertSame($priorId, $cross['id']);
        $this->assertSame([['run_id' => 'run-prior', 'seq' => 4]], $cross['source_refs']);
        $this->assertCount(1, $cross['events']);
        $this->assertSame('run-prior-4', $cross['events'][0]['payload']['text']);

        $current = $service->recall('run-current', substr($currentId, 0, 12));
        $this->assertTrue($current['ok']);
        $this->assertSame('run-current', $current['session_id']);
        $this->assertSame($currentId, $current['id']);
    }

    private function api(string $cwd): ExtensionApiInterface
    {
        return new class($cwd) implements ExtensionApiInterface {
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

            public function registerSkill(string $skillDirectory): void
            {
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
    }

    private function apiWithReader(string $cwd, SessionEventReaderInterface $reader): ExtensionApiInterface
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

            public function registerSkill(string $skillDirectory): void
            {
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
