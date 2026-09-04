<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\ObservationalMemory\Tests;

use Ineersa\CodingAgent\Extension\Agent\ConfiguredModelAgentRunner;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;
use Ineersa\Hatfield\ExtensionApi\Agent\AgentCallRequestDTO;
use Ineersa\Hatfield\ExtensionApi\Agent\AgentToolDTO;
use Ineersa\Hatfield\ExtensionApi\Tool\ExtensionToolHandlerInterface;
use Ineersa\HatfieldExt\ObservationalMemory\Compaction\RecordReflectionsToolHandler;
use Ineersa\HatfieldExt\ObservationalMemory\Compaction\ReflectorSystemPrompt;
use Ineersa\HatfieldExt\ObservationalMemory\Observer\ObserverSystemPrompt;
use Ineersa\HatfieldExt\ObservationalMemory\Observer\RecordObservationsToolHandler;
use Ineersa\HatfieldExt\ObservationalMemory\Storage\ObservationRepository;
use Ineersa\HatfieldExt\ObservationalMemory\Support\OmIdentity;
use Ineersa\HatfieldExt\ObservationalMemory\Tests\Support\OmDatabaseFactoryTestService;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\NullLogger;

/**
 * Thesis: live ConfiguredModelAgentRunner can drive OM Observer multi-call,
 * delta Reflector, and Dropper tools against llama_cpp_test/test (tool/storage
 * contracts, not prose) with stable cache keys.
 */
#[Group('llm-real')]
final class OmLiveLlmSmokeTest extends IsolatedKernelTestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpDir = TestDirectoryIsolation::createProjectTempDir('om-live-llm');
        TestDirectoryIsolation::createHatfieldTree($this->tmpDir);
    }

    protected function tearDown(): void
    {
        TestDirectoryIsolation::removeDirectory($this->tmpDir);
        parent::tearDown();
    }

    #[Test]
    public function liveObserverCanRecordObservationsViaTwoToolCalls(): void
    {
        /** @var ConfiguredModelAgentRunner $runner */
        $runner = self::getContainer()->get(ConfiguredModelAgentRunner::class);

        $handler = new RecordObservationsToolHandler(
            runId: 'run-live-obs',
            observerSchemaVersion: '1',
            allowedSourceRefs: [
                ['run_id' => 'run-live-obs', 'seq' => 1],
                ['run_id' => 'run-live-obs', 'seq' => 2],
            ],
        );
        $invocationCount = 0;
        $countingHandler = new class($handler, $invocationCount) implements ExtensionToolHandlerInterface {
            public function __construct(
                private readonly RecordObservationsToolHandler $inner,
                private int &$invocationCount,
            ) {
            }

            public function __invoke(array $arguments): mixed
            {
                ++$this->invocationCount;

                return ($this->inner)($arguments);
            }
        };

        // Stable scenario tag for llama-proxy cache normalization (no random content).
        $scenario = '[llm-real:om-observer-multi-call]';
        $runner->run(new AgentCallRequestDTO(
            model: 'llama_cpp_test/test',
            sessionId: 'run-live-obs',
            instructions: ObserverSystemPrompt::text()."\n\nYou MUST call record_observations exactly twice with two sequential tool calls. Each call records exactly one observation. Do not combine both observations into one call.",
            input: implode("\n", [
                'CURRENT REFLECTIONS:',
                '(none)',
                '',
                'CURRENT OBSERVATIONS:',
                '(none)',
                '',
                'NEW SOURCE-ADDRESSED CONVERSATION CHUNK:',
                '[Source entry id: run-live-obs:1]',
                '[User @ 2026-07-26 12:00]: '.$scenario.' User stated they use Postgres for the project database.',
                '[Source entry id: run-live-obs:2]',
                '[User @ 2026-07-26 12:01]: '.$scenario.' User stated they deploy with feature flags.',
                '',
                'Current local time fallback: 2026-07-26 12:05',
                '',
                'Call record_observations twice:',
                '1) First call: one observation content "User stated they use Postgres for the project database.", timestamp 2026-07-26 12:00, relevance high, source_refs [{"run_id":"run-live-obs","seq":1}].',
                '2) Second call: one observation content "User stated they deploy with feature flags.", timestamp 2026-07-26 12:01, relevance high, source_refs [{"run_id":"run-live-obs","seq":2}].',
            ]),
            tools: [
                new AgentToolDTO(
                    name: 'record_observations',
                    description: 'Record observations for the chunk.',
                    parametersJsonSchema: [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['observations'],
                        'properties' => [
                            'observations' => [
                                'type' => 'array',
                                'items' => [
                                    'type' => 'object',
                                    'additionalProperties' => false,
                                    'required' => ['timestamp', 'relevance', 'content', 'source_refs'],
                                    'properties' => [
                                        'timestamp' => ['type' => 'string'],
                                        'relevance' => ['type' => 'string', 'enum' => ['low', 'medium', 'high', 'critical']],
                                        'content' => ['type' => 'string'],
                                        'source_refs' => [
                                            'type' => 'array',
                                            'items' => [
                                                'type' => 'object',
                                                'required' => ['run_id', 'seq'],
                                                'properties' => [
                                                    'run_id' => ['type' => 'string'],
                                                    'seq' => ['type' => 'integer'],
                                                ],
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                    handler: $countingHandler, // test-local counting wrapper around real handler
                ),
            ],
            maxToolCalls: 6,
        ));

        $this->assertGreaterThanOrEqual(2, $invocationCount, 'Observer model must invoke record_observations at least twice');
        $collected = $handler->collected();
        $this->assertGreaterThanOrEqual(2, \count($collected), 'Observer must accept at least two observations across tool calls');
        $contents = array_map(static fn (array $observation): string => $observation['content'], $collected);
        $this->assertNotEmpty(array_filter(
            $contents,
            static fn (string $content): bool => str_contains(strtolower($content), 'postgres') || str_contains(strtolower($content), 'feature flag'),
        ), 'Collected observations must include expected multi-call content');

        $dbPath = $this->tmpDir.'/.hatfield/extensions-data/observational-memory/live-obs.sqlite';
        $connection = $this->omDatabaseFactory()->connectAndMigrate($dbPath, new NullLogger());
        $repo = new ObservationRepository($connection);
        $now = '2026-07-26T12:05:00+00:00';
        $repo->commitChunkPartCoverage(
            coverageKey: 'live-obs-cov',
            runId: 'run-live-obs',
            boundaryKey: 'live-obs-boundary',
            sourceStartSeq: 1,
            sourceEndSeq: 2,
            chunkKey: 'live-obs-chunk',
            partIndex: 1,
            partCount: 1,
            sourceDigest: 'live-obs-digest',
            partDigest: 'live-obs-part',
            rendererVersion: '1',
            observerSchemaVersion: '1',
            observerModel: 'llama_cpp_test/test',
            observations: $collected,
            coveredAt: $now,
        );
        $this->assertSame(2, $repo->contiguousCoveredEndSeq('run-live-obs', '1', '1'));
        $this->assertGreaterThanOrEqual(2, \count($repo->listObservationsForRun('run-live-obs')));
    }

    #[Test]
    public function liveReflectorAndDropperToolsAcceptSharedModelCalls(): void
    {
        /** @var ConfiguredModelAgentRunner $runner */
        $runner = self::getContainer()->get(ConfiguredModelAgentRunner::class);

        $obsId = OmIdentity::observationId(
            'run-live-ref',
            '1',
            '2026-07-26 12:00',
            'User stated they use Postgres for the project database.',
            [['run_id' => 'run-live-ref', 'seq' => 1]],
        );

        $handler = new RecordReflectionsToolHandler(
            runId: 'run-live-ref',
            reflectorSchemaVersion: '1',
            existingReflectionIds: [],
            allowedObservationIds: [$obsId => true],
        );

        $scenario = '[llm-real:om-reflector-delta]';
        $runner->run(new AgentCallRequestDTO(
            model: 'llama_cpp_test/test',
            sessionId: 'run-live-ref',
            instructions: ReflectorSystemPrompt::text()."\n\nYou MUST call record_reflections at least once with one new durable reflection.",
            input: implode("\n", [
                'CURRENT REFLECTIONS:',
                '(none yet)',
                '',
                'CURRENT OBSERVATIONS:',
                \sprintf('[%s] 2026-07-26 12:00 [high] [coverage: none] User stated they use Postgres for the project database. %s', $obsId, $scenario),
                '',
                'Crystallize any missing durable facts or patterns into new reflections. If nothing is stable enough, do not call the tool.',
                '',
                'Call record_reflections once with content about Postgres and supporting_observation_ids containing only the observation id above.',
            ]),
            tools: [
                new AgentToolDTO(
                    name: 'record_reflections',
                    description: 'Record new durable reflections with supporting observation ids.',
                    parametersJsonSchema: [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['reflections'],
                        'properties' => [
                            'reflections' => [
                                'type' => 'array',
                                'minItems' => 1,
                                'items' => [
                                    'type' => 'object',
                                    'additionalProperties' => false,
                                    'required' => ['content', 'supporting_observation_ids'],
                                    'properties' => [
                                        'content' => ['type' => 'string'],
                                        'supporting_observation_ids' => [
                                            'type' => 'array',
                                            'minItems' => 1,
                                            'items' => ['type' => 'string'],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                    handler: $handler,
                ),
            ],
            maxToolCalls: 16,
        ));

        $this->assertNotEmpty($handler->newReflections(), 'delta Reflector must accept at least one new reflection');
        $this->assertSame($obsId, $handler->newReflections()[0]['supporting_observation_ids'][0] ?? null);

        $dropHandler = new \Ineersa\HatfieldExt\ObservationalMemory\Compaction\DropObservationsToolHandler(
            allowedObservationIds: [$obsId => true],
            maxDropsAllowed: 1,
        );
        $dropScenario = '[llm-real:om-dropper-tool]';
        $runner->run(new AgentCallRequestDTO(
            model: 'llama_cpp_test/test',
            sessionId: 'run-live-drop',
            instructions: \Ineersa\HatfieldExt\ObservationalMemory\Compaction\DropperSystemPrompt::text()."\n\nYou MUST call drop_observations once proposing the observation id from the list.",
            input: implode("\n", [
                'CURRENT REFLECTIONS:',
                \sprintf('[r1] User stated they use Postgres. %s', $dropScenario),
                '',
                'CURRENT OBSERVATIONS:',
                \sprintf('[%s] 2026-07-26 12:00 [low] [coverage: strong] User stated they use Postgres for the project database.', $obsId),
                '',
                'Active observation pool: ~100 tokens; target: ~50 tokens; fullness against target: ~200%; over target by ~50 tokens.',
                'Maximum drops allowed this run: 1 observation. This maximum is sized to move the active pool toward the target if every proposed drop is clearly safe.',
                'This maximum is a hard upper bound, not a target. Drop fewer or none if fewer observations are clearly safe.',
            ]),
            tools: [
                new AgentToolDTO(
                    name: 'drop_observations',
                    description: 'Propose active observation ids that are safe to remove from compacted memory.',
                    parametersJsonSchema: [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['ids'],
                        'properties' => [
                            'ids' => [
                                'type' => 'array',
                                'minItems' => 1,
                                'items' => ['type' => 'string'],
                            ],
                            'reason' => ['type' => 'string'],
                        ],
                    ],
                    handler: $dropHandler,
                ),
            ],
            maxToolCalls: 16,
        ));

        $this->assertContains($obsId, $dropHandler->proposedIds(), 'Dropper tool path must accumulate proposed ids');
    }

    private function omDatabaseFactory(): OmDatabaseFactoryTestService
    {
        /** @var OmDatabaseFactoryTestService $service */
        $service = self::getContainer()->get('test.om_database_factory');

        return $service;
    }
}
