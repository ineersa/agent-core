<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\ObservationalMemory\Compaction;

use Ineersa\Hatfield\ExtensionApi\Agent\AgentCallRequestDTO;
use Ineersa\Hatfield\ExtensionApi\Agent\AgentToolDTO;
use Ineersa\Hatfield\ExtensionApi\ExtensionApiInterface;
use Ineersa\HatfieldExt\ObservationalMemory\Runtime\OmSettings;
use Ineersa\HatfieldExt\ObservationalMemory\Storage\MemoryGenerationRepository;
use Ineersa\HatfieldExt\ObservationalMemory\Storage\ObservationRepository;

/**
 * Pi-style delta Reflector: input assembly + accumulate/dedupe new reflections only.
 */
final class ReflectorPipeline
{
    public function __construct(
        private readonly \Psr\Log\LoggerInterface $logger,
    ) {
    }

    /**
     * @return array{
     *   prior_reflections: list<array{
     *     reflection_id: string,
     *     content: string,
     *     supporting_observation_ids: list<string>,
     *     supporting_observation_ids_json: string,
     *     token_count: int
     *   }>,
     *   new_reflections: list<array{
     *     reflection_id: string,
     *     content: string,
     *     supporting_observation_ids: list<string>,
     *     supporting_observation_ids_json: string,
     *     token_count: int
     *   }>,
     *   active_observations: list<array{
     *     observation_id: string,
     *     content: string,
     *     relevance: string,
     *     timestamp: string,
     *     token_count: int
     *   }>
     * }
     */
    public function produceDelta(
        ExtensionApiInterface $api,
        ObservationRepository $observationRepo,
        MemoryGenerationRepository $generationRepo,
        OmSettings $settings,
        string $runId,
        string $model,
        ?string $jobId,
        ?string $correlationId,
    ): array {
        $activeReflections = $generationRepo->listActiveReflections($runId);
        $activeObservations = $observationRepo->listActiveCandidateObservations($runId);

        if ([] === $activeReflections && [] === $activeObservations) {
            throw new ReflectorException('no_active_memory', 'Reflector invoked with empty active memory.');
        }

        $prior = [];
        $existingIds = [];
        $supportCounts = [];
        foreach ($activeReflections as $reflection) {
            $id = $reflection['reflection_id'];
            $existingIds[$id] = true;
            $support = $this->decodeSupportIds($reflection['supporting_observation_ids_json']);
            $prior[] = [
                'reflection_id' => $id,
                'content' => $reflection['content'],
                'supporting_observation_ids' => $support,
                'supporting_observation_ids_json' => $reflection['supporting_observation_ids_json'],
                'token_count' => $reflection['token_count'],
            ];
            foreach ($support as $obsId) {
                $supportCounts[$obsId] = ($supportCounts[$obsId] ?? 0) + 1;
            }
        }

        $allowedObservationIds = [];
        foreach ($activeObservations as $observation) {
            $allowedObservationIds[$observation['observation_id']] = true;
        }

        $input = $this->buildUserInput($activeReflections, $activeObservations, $supportCounts);
        $toolHandler = new RecordReflectionsToolHandler(
            runId: $runId,
            reflectorSchemaVersion: $settings->reflectorSchemaVersion,
            existingReflectionIds: $existingIds,
            allowedObservationIds: $allowedObservationIds,
        );

        $api->agent()->run(new AgentCallRequestDTO(
            model: $model,
            sessionId: $runId,
            instructions: ReflectorSystemPrompt::text(),
            input: $input,
            tools: [
                new AgentToolDTO(
                    name: 'record_reflections',
                    description: 'Record new durable reflections with supporting observation ids. Call as needed; zero calls is valid when nothing is stable enough.',
                    parametersJsonSchema: [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['reflections'],
                        'properties' => [
                            'reflections' => [
                                'type' => 'array',
                                'minItems' => 1,
                                'description' => 'New durable reflections only (delta). Existing reflections are retained automatically.',
                                'items' => [
                                    'type' => 'object',
                                    'additionalProperties' => false,
                                    'required' => ['content', 'supporting_observation_ids'],
                                    'properties' => [
                                        'content' => [
                                            'type' => 'string',
                                            'minLength' => 1,
                                            'description' => 'Single-line plain prose durable fact.',
                                        ],
                                        'supporting_observation_ids' => [
                                            'type' => 'array',
                                            'minItems' => 1,
                                            'items' => ['type' => 'string', 'minLength' => 1],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                    handler: $toolHandler,
                ),
            ],
            correlationId: $jobId ?? $correlationId,
            maxToolCalls: OmSettings::DEFAULT_AGENT_MAX_TOOL_CALLS,
        ));

        $newReflections = $toolHandler->newReflections();
        $this->logger->info('om.reflector.delta_complete', [
            'component' => 'observational_memory',
            'event_type' => 'om.reflector.delta_complete',
            'run_id' => $runId,
            'job_id' => $jobId,
            'prior_reflection_count' => \count($prior),
            'new_reflection_count' => \count($newReflections),
            'active_observation_count' => \count($activeObservations),
        ]);

        return [
            'prior_reflections' => $prior,
            'new_reflections' => $newReflections,
            'active_observations' => $activeObservations,
        ];
    }

    /**
     * @param list<array{reflection_id: string, content: string, supporting_observation_ids_json: string, token_count: int, position: int}> $activeReflections
     * @param list<array{observation_id: string, content: string, relevance: string, timestamp: string, token_count: int}>                  $activeObservations
     * @param array<string, int>                                                                                                            $supportCounts
     */
    private function buildUserInput(
        array $activeReflections,
        array $activeObservations,
        array $supportCounts,
    ): string {
        usort($activeReflections, static function (array $a, array $b): int {
            $byPos = ($a['position'] ?? 0) <=> ($b['position'] ?? 0);
            if (0 !== $byPos) {
                return $byPos;
            }

            return strcmp($a['reflection_id'], $b['reflection_id']);
        });
        usort($activeObservations, static function (array $a, array $b): int {
            $byTs = strcmp($a['timestamp'], $b['timestamp']);
            if (0 !== $byTs) {
                return $byTs;
            }

            return strcmp($a['observation_id'], $b['observation_id']);
        });

        $lines = ['CURRENT REFLECTIONS:'];
        if ([] === $activeReflections) {
            $lines[] = '(none yet)';
        } else {
            foreach ($activeReflections as $reflection) {
                $lines[] = \sprintf('[%s] %s', $reflection['reflection_id'], $reflection['content']);
            }
        }

        $lines[] = '';
        $lines[] = 'CURRENT OBSERVATIONS:';
        if ([] === $activeObservations) {
            $lines[] = '(none yet)';
        } else {
            foreach ($activeObservations as $observation) {
                $count = $supportCounts[$observation['observation_id']] ?? 0;
                $tier = match (true) {
                    $count >= 2 => 'strong',
                    1 === $count => 'partial',
                    default => 'none',
                };
                $lines[] = \sprintf(
                    '[%s] %s [%s] [coverage: %s] %s',
                    $observation['observation_id'],
                    $observation['timestamp'],
                    $observation['relevance'],
                    $tier,
                    $observation['content'],
                );
            }
        }

        $lines[] = '';
        $lines[] = 'Crystallize any missing durable facts or patterns into new reflections. If nothing is stable enough, do not call the tool.';

        return implode("\n", $lines);
    }

    /**
     * @return list<string>
     */
    private function decodeSupportIds(string $json): array
    {
        try {
            $decoded = json_decode($json, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }
        if (!\is_array($decoded)) {
            return [];
        }
        $out = [];
        foreach ($decoded as $id) {
            if (\is_string($id) && '' !== $id) {
                $out[] = $id;
            }
        }

        return $out;
    }
}
