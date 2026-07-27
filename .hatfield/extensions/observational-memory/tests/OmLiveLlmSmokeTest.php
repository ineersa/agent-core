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
 * Thesis: live ConfiguredModelAgentRunner can drive OM Observer multi-call and
 * Reflector complete-generation tools against llama_cpp_test/test (tool/storage
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
            maxToolCalls: 100,
        ));

        $this->assertGreaterThanOrEqual(2, $invocationCount, 'Observer model must invoke record_observations at least twice');
        $this->assertTrue($handler->hasAnyCall(), 'Observer model must call record_observations');
        $collected = $handler->collected();
        $this->assertGreaterThanOrEqual(2, \count($collected), 'Observer must accept at least two observations across tool calls');

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
    public function liveReflectorCanRecordCompleteGenerationViaTool(): void
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
            allowedReflectionIds: [],
            allowedObservationIds: [$obsId => true],
            activeReflectionsById: [],
            requireNonEmptyOutput: true,
        );

        // Stable scenario tag for llama-proxy cache normalization (no random content).
        $scenario = '[llm-real:om-reflector-complete-generation]';
        $runner->run(new AgentCallRequestDTO(
            model: 'llama_cpp_test/test',
            sessionId: 'run-live-ref',
            instructions: ReflectorSystemPrompt::text()."\n\nYou MUST call record_reflections at least once with a complete next generation.",
            input: implode("\n", [
                'CURRENT REFLECTIONS:',
                '(none)',
                '',
                'CURRENT OBSERVATIONS:',
                \sprintf('[%s] 2026-07-26 12:00 [high] [coverage: none] User stated they use Postgres for the project database. %s', $obsId, $scenario),
                '',
                'Current local time fallback: 2026-07-26 12:05',
                '',
                'Call record_reflections once with one new reflection about Postgres supporting the observation id above, and retained_observation_ids containing that same id.',
            ]),
            tools: [
                new AgentToolDTO(
                    name: 'record_reflections',
                    description: 'Record complete next active generation.',
                    parametersJsonSchema: [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['reflections', 'retained_observation_ids'],
                        'properties' => [
                            'reflections' => [
                                'type' => 'array',
                                'items' => [
                                    'type' => 'object',
                                    'properties' => [
                                        'content' => ['type' => 'string'],
                                        'supporting_observation_ids' => [
                                            'type' => 'array',
                                            'items' => ['type' => 'string'],
                                        ],
                                        'retain_id' => ['type' => 'string'],
                                    ],
                                ],
                            ],
                            'retained_observation_ids' => [
                                'type' => 'array',
                                'items' => ['type' => 'string'],
                            ],
                        ],
                    ],
                    handler: $handler,
                ),
            ],
            maxToolCalls: 100,
        ));

        $this->assertTrue($handler->hasCandidate(), 'Reflector model must produce a valid candidate generation');
        $this->assertNotEmpty($handler->reflections());
        $this->assertContains($obsId, $handler->retainedObservationIds());
    }

    private function omDatabaseFactory(): OmDatabaseFactoryTestService
    {
        /** @var OmDatabaseFactoryTestService $service */
        $service = self::getContainer()->get('test.om_database_factory');

        return $service;
    }
}
