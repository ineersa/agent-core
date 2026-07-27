<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\ObservationalMemory\Tests;

use Ineersa\CodingAgent\Extension\Agent\ConfiguredModelAgentRunner;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;
use Ineersa\Hatfield\ExtensionApi\Agent\AgentCallRequestDTO;
use Ineersa\Hatfield\ExtensionApi\Agent\AgentToolDTO;
use Ineersa\HatfieldExt\ObservationalMemory\Compaction\RecordReflectionsToolHandler;
use Ineersa\HatfieldExt\ObservationalMemory\Compaction\ReflectorSystemPrompt;
use Ineersa\HatfieldExt\ObservationalMemory\Observer\ObserverSystemPrompt;
use Ineersa\HatfieldExt\ObservationalMemory\Observer\RecordObservationsToolHandler;
use Ineersa\HatfieldExt\ObservationalMemory\Support\OmIdentity;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * Thesis: live ConfiguredModelAgentRunner can drive OM Observer and Reflector
 * tool schemas against llama_cpp_test/test (tool/storage contracts, not prose).
 */
#[Group('llm-real')]
final class OmLiveLlmSmokeTest extends IsolatedKernelTestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpDir = TestDirectoryIsolation::createProjectTempDir('om-live-llm');
    }

    protected function tearDown(): void
    {
        TestDirectoryIsolation::removeDirectory($this->tmpDir);
        parent::tearDown();
    }

    #[Test]
    public function liveObserverCanRecordObservationsViaTool(): void
    {
        /** @var ConfiguredModelAgentRunner $runner */
        $runner = self::getContainer()->get(ConfiguredModelAgentRunner::class);

        $handler = new RecordObservationsToolHandler(
            runId: 'run-live-obs',
            observerSchemaVersion: '1',
            allowedSourceRefs: [
                ['run_id' => 'run-live-obs', 'seq' => 1],
            ],
        );

        $unique = '[llm-real:om-observer-'.bin2hex(random_bytes(4)).']';
        $runner->run(new AgentCallRequestDTO(
            model: 'llama_cpp_test/test',
            sessionId: 'run-live-obs',
            instructions: ObserverSystemPrompt::text()."\n\nYou MUST call record_observations at least once.",
            input: implode("\n", [
                'CURRENT REFLECTIONS:',
                '(none)',
                '',
                'CURRENT OBSERVATIONS:',
                '(none)',
                '',
                'NEW SOURCE-ADDRESSED CONVERSATION CHUNK:',
                '[Source entry id: run-live-obs:1]',
                '[User @ 2026-07-26 12:00]: '.$unique.' User stated they use Postgres for the project database.',
                '',
                'Current local time fallback: 2026-07-26 12:05',
                '',
                'Call record_observations exactly once with one observation: content about Postgres, timestamp 2026-07-26 12:00, relevance high, source_refs [{"run_id":"run-live-obs","seq":1}].',
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
                    handler: $handler,
                ),
            ],
            maxToolCalls: 100,
        ));

        $this->assertTrue($handler->hasAnyCall(), 'Observer model must call record_observations at least once');
        $this->assertNotEmpty($handler->collected(), 'Observer must accept at least one observation');
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

        $unique = '[llm-real:om-reflector-'.bin2hex(random_bytes(4)).']';
        $runner->run(new AgentCallRequestDTO(
            model: 'llama_cpp_test/test',
            sessionId: 'run-live-ref',
            instructions: ReflectorSystemPrompt::text()."\n\nYou MUST call record_reflections at least once.",
            input: implode("\n", [
                'CURRENT REFLECTIONS:',
                '(none)',
                '',
                'CURRENT OBSERVATIONS:',
                \sprintf('[%s] 2026-07-26 12:00 [high] [coverage: none] User stated they use Postgres for the project database. %s', $obsId, $unique),
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
}
