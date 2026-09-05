<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\ObservationalMemory\Tests;

use HelgeSverre\Toon\Toon;
use Ineersa\AgentCore\Application\Tool\StackToolExecutionContextAccessor;
use Ineersa\AgentCore\Application\Tool\ToolContext;
use Ineersa\AgentCore\Contract\Hook\CancellationTokenInterface;
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
 * Numeric-looking run IDs (e.g. "5") must not TypeError on SessionEventReader::readRange.
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
                // Malformed mixed refs: foreign run must be filtered from returned source_refs.
                'source_refs_json' => json_encode([
                    ['run_id' => 'run-current', 'seq' => 2],
                    ['run_id' => 'run-foreign', 'seq' => 99],
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
            'model' => 'llama_cpp_test/test',
            'observer' => [],
            'reflector' => [],
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
        $this->assertIsString($observationResult);
        $this->assertStringNotContainsString('"ok":', $observationResult);
        $observationResult = Toon::decode($observationResult);
        $this->assertIsArray($observationResult);
        $this->assertTrue($observationResult['ok']);
        $this->assertSame('observation', $observationResult['kind']);
        $this->assertSame([
            ['run_id' => 'run-current', 'seq' => 2],
            ['run_id' => 'run-current', 'seq' => 4],
        ], $observationResult['source_refs']);
        $this->assertCount(2, $observationResult['events']);
        $this->assertSame(2, $observationResult['events'][0]['seq']);
        $this->assertSame(4, $observationResult['events'][1]['seq']);
        $this->assertStringNotContainsString('run-foreign', json_encode($observationResult, \JSON_THROW_ON_ERROR));

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
        $this->assertIsString($reflectionResult);
        $this->assertStringNotContainsString('"ok":', $reflectionResult);
        $reflectionResult = Toon::decode($reflectionResult);
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
        $this->assertIsString($invalid);
        $this->assertStringNotContainsString('"ok":', $invalid);
        $invalid = Toon::decode($invalid);
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
        $this->assertIsString($cross);
        $this->assertStringNotContainsString('"ok":', $cross);
        $cross = Toon::decode($cross);
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
        $this->assertIsString($foreignSupport);
        $this->assertStringNotContainsString('"ok":', $foreignSupport);
        $foreignSupport = Toon::decode($foreignSupport);
        $this->assertIsArray($foreignSupport);
        $this->assertTrue($foreignSupport['ok']);
        $this->assertSame('reflection', $foreignSupport['kind']);
        $this->assertSame([], $foreignSupport['supporting_observation_ids']);
        $this->assertSame([], $foreignSupport['source_refs']);
        $this->assertSame([], $foreignSupport['events']);
        $this->assertStringNotContainsString($otherId, json_encode($foreignSupport, \JSON_THROW_ON_ERROR));
    }

    #[Test]
    public function numericLookingRunIdPassesStringToSessionEventReader(): void
    {
        $dbPath = $this->tmpDir.'/om-numeric-run.sqlite';
        $connection = $this->omDatabaseFactory()->connectAndMigrate($dbPath);
        $obs = new ObservationRepository($connection);
        $gen = new MemoryGenerationRepository($connection);

        $runId = '5';
        $obsId = str_repeat('a', 64);
        $refId = str_repeat('b', 64);

        $obs->commitChunkPartCoverage(
            coverageKey: 'cov-5',
            runId: $runId,
            boundaryKey: 'b5',
            sourceStartSeq: 1,
            sourceEndSeq: 3,
            chunkKey: 'chunk-5',
            partIndex: 1,
            partCount: 1,
            sourceDigest: 'd5',
            partDigest: 'p5',
            rendererVersion: '1',
            observerSchemaVersion: '1',
            observerModel: 'llama_cpp_test/test',
            observations: [[
                'observation_id' => $obsId,
                'content' => 'Numeric run observation',
                'content_hash' => hash('sha256', 'Numeric run observation'),
                'relevance' => 'high',
                'timestamp' => '2026-07-28 14:00',
                'token_count' => 8,
                'source_refs_json' => json_encode([
                    ['run_id' => $runId, 'seq' => 1],
                    ['run_id' => $runId, 'seq' => 3],
                    // Foreign ref must still be filtered.
                    ['run_id' => '99', 'seq' => 1],
                ], \JSON_THROW_ON_ERROR),
            ]],
            coveredAt: '2026-07-28T14:00:00+00:00',
        );

        $gen->claimGeneration(
            generationId: 'gen-5',
            runId: $runId,
            triggerKind: MemoryGenerationRepository::TRIGGER_THRESHOLD,
            observationSetHash: hash('sha256', 'set-5'),
            reflectorModel: 'llama_cpp_test/test',
            reflectorSchemaVersion: '1',
            now: '2026-07-28T14:01:00+00:00',
            requiredStartSeq: 1,
            requiredEndSeq: 3,
        );
        $gen->commitSucceededGeneration(
            generationId: 'gen-5',
            runId: $runId,
            observationSetHash: hash('sha256', 'set-5'),
            reflectorModel: 'llama_cpp_test/test',
            reflectorSchemaVersion: '1',
            reflections: [[
                'reflection_id' => $refId,
                'content' => 'Numeric run reflection',
                'supporting_observation_ids_json' => json_encode([$obsId], \JSON_THROW_ON_ERROR),
                'token_count' => 4,
            ]],
            retainedObservationIds: [$obsId],
            now: '2026-07-28T14:01:01+00:00',
        );

        $reader = new class implements SessionEventReaderInterface {
            /** @var list<string> */
            public array $seenRunIds = [];

            public function readRange(string $runId, int $startSeq, int $endSeq): iterable
            {
                $this->seenRunIds[] = $runId;
                for ($seq = $startSeq; $seq <= $endSeq; ++$seq) {
                    yield new SessionEventDTO(
                        runId: $runId,
                        seq: $seq,
                        turnNo: 1,
                        type: 'message',
                        payload: ['seq' => $seq],
                        createdAt: '2026-07-28T14:00:00+00:00',
                    );
                }
            }
        };

        $api = $this->api($this->tmpDir, $reader);
        $settings = OmSettings::fromArray([
            'storage' => ['database' => $dbPath],
            'model' => 'llama_cpp_test/test',
            'observer' => [],
            'reflector' => [],
        ]);
        $handler = new RecallToolHandler(new OmQueryService($api, $settings));
        $accessor = new StackToolExecutionContextAccessor();
        $adapter = new ExtensionToolHandlerAdapter($handler, $accessor);

        $obsResult = $accessor->with(
            new ToolContext(
                runId: $runId,
                turnNo: 1,
                toolCallId: 'tc-num-obs',
                toolName: 'recall',
                cancellationToken: new NullCancellationToken(),
                timeoutSeconds: null,
            ),
            static fn (): mixed => $adapter(['id' => $obsId]),
        );
        $this->assertIsString($obsResult);
        $this->assertStringNotContainsString('"ok":', $obsResult);
        $obsResult = Toon::decode($obsResult);
        $this->assertIsArray($obsResult);
        $this->assertTrue($obsResult['ok']);
        $this->assertSame('observation', $obsResult['kind']);
        $this->assertCount(2, $obsResult['events']);
        $this->assertSame(['run_id' => '5', 'seq' => 1], $obsResult['source_refs'][0]);
        $this->assertSame(['run_id' => '5', 'seq' => 3], $obsResult['source_refs'][1]);
        $this->assertStringNotContainsString('"99"', json_encode($obsResult, \JSON_THROW_ON_ERROR));

        $refResult = $accessor->with(
            new ToolContext(
                runId: $runId,
                turnNo: 1,
                toolCallId: 'tc-num-ref',
                toolName: 'recall',
                cancellationToken: new NullCancellationToken(),
                timeoutSeconds: null,
            ),
            static fn (): mixed => $adapter(['id' => $refId]),
        );
        $this->assertIsString($refResult);
        $this->assertStringNotContainsString('"ok":', $refResult);
        $refResult = Toon::decode($refResult);
        $this->assertIsArray($refResult);
        $this->assertTrue($refResult['ok']);
        $this->assertSame('reflection', $refResult['kind']);
        $this->assertCount(2, $refResult['events']);

        // 12-char unique prefix resolves; mistyped prefix remains not_found.
        $prefixOk = $accessor->with(
            new ToolContext(
                runId: $runId,
                turnNo: 1,
                toolCallId: 'tc-num-prefix',
                toolName: 'recall',
                cancellationToken: new NullCancellationToken(),
                timeoutSeconds: null,
            ),
            static fn (): mixed => $adapter(['id' => substr($obsId, 0, 12)]),
        );
        $this->assertIsString($prefixOk);
        $this->assertStringNotContainsString('"ok":', $prefixOk);
        $prefixOk = Toon::decode($prefixOk);
        $this->assertIsArray($prefixOk);
        $this->assertTrue($prefixOk['ok']);

        $mistyped = $accessor->with(
            new ToolContext(
                runId: $runId,
                turnNo: 1,
                toolCallId: 'tc-num-typo',
                toolName: 'recall',
                cancellationToken: new NullCancellationToken(),
                timeoutSeconds: null,
            ),
            // One-char typo at position 11 of the 12-char prefix (c vs a).
            static fn (): mixed => $adapter(['id' => 'aaaaaaaaaaac']),
        );
        $this->assertIsString($mistyped);
        $this->assertStringNotContainsString('"ok":', $mistyped);
        $mistyped = Toon::decode($mistyped);
        $this->assertIsArray($mistyped);
        $this->assertFalse($mistyped['ok']);
        $this->assertSame('not_found', $mistyped['error']);

        $this->assertNotEmpty($reader->seenRunIds);
        foreach ($reader->seenRunIds as $seen) {
            $this->assertIsString($seen);
            $this->assertSame('5', $seen);
        }
    }

    #[Test]
    public function recallReturnsStructuredCancelledBeforeHydrationWhenTokenAlreadySet(): void
    {
        // Thesis: cooperative cancel must return a structured cancelled map without TOON encoding
        // and without reading session events once cancellation is already requested.
        $dbPath = $this->tmpDir.'/om-cancel.sqlite';
        $connection = $this->omDatabaseFactory()->connectAndMigrate($dbPath);
        $obs = new ObservationRepository($connection);

        $obsId = str_repeat('a', 64);
        $obs->commitChunkPartCoverage(
            coverageKey: 'cov-cancel',
            runId: 'run-cancel',
            boundaryKey: 'b-cancel',
            sourceStartSeq: 1,
            sourceEndSeq: 2,
            chunkKey: 'chunk-cancel',
            partIndex: 1,
            partCount: 1,
            sourceDigest: 'd-cancel',
            partDigest: 'p-cancel',
            rendererVersion: '1',
            observerSchemaVersion: '1',
            observerModel: 'llama_cpp_test/test',
            observations: [[
                'observation_id' => $obsId,
                'content' => 'Should not hydrate events when cancelled',
                'content_hash' => hash('sha256', 'Should not hydrate events when cancelled'),
                'relevance' => 'medium',
                'timestamp' => '2026-07-28 13:00',
                'token_count' => 10,
                'source_refs_json' => json_encode([
                    ['run_id' => 'run-cancel', 'seq' => 1],
                ], \JSON_THROW_ON_ERROR),
            ]],
            coveredAt: '2026-07-28T13:00:00+00:00',
        );

        $reader = new class implements SessionEventReaderInterface {
            public int $reads = 0;

            public function readRange(string $runId, int $startSeq, int $endSeq): iterable
            {
                ++$this->reads;
                yield new SessionEventDTO(
                    runId: $runId,
                    seq: 1,
                    turnNo: 1,
                    type: 'message',
                    payload: ['seq' => 1],
                    createdAt: '2026-07-28T13:00:00+00:00',
                );
            }
        };

        $api = $this->api($this->tmpDir, $reader);
        $settings = OmSettings::fromArray([
            'storage' => ['database' => $dbPath],
            'model' => 'llama_cpp_test/test',
            'observer' => [],
            'reflector' => [],
        ]);
        $handler = new RecallToolHandler(new OmQueryService($api, $settings));
        $accessor = new StackToolExecutionContextAccessor();
        $adapter = new ExtensionToolHandlerAdapter($handler, $accessor);

        $cancelToken = new class implements CancellationTokenInterface {
            public function isCancellationRequested(): bool
            {
                return true;
            }
        };

        $result = $accessor->with(
            new ToolContext(
                runId: 'run-cancel',
                turnNo: 1,
                toolCallId: 'tc-cancel',
                toolName: 'recall',
                cancellationToken: $cancelToken,
                timeoutSeconds: null,
            ),
            static fn (): mixed => $adapter(['id' => $obsId]),
        );

        $this->assertIsArray($result);
        $this->assertTrue($result['cancelled'] ?? false);
        $this->assertSame(0, $reader->reads, 'cancelled recall must not hydrate session events');
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
