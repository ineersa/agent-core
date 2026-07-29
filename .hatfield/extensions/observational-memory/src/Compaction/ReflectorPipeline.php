<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\ObservationalMemory\Compaction;

use Ineersa\Hatfield\ExtensionApi\Agent\AgentCallRequestDTO;
use Ineersa\Hatfield\ExtensionApi\Agent\AgentToolDTO;
use Ineersa\Hatfield\ExtensionApi\ExtensionApiInterface;
use Ineersa\HatfieldExt\ObservationalMemory\Observer\OmTokenEstimator;
use Ineersa\HatfieldExt\ObservationalMemory\Runtime\OmSettings;
use Ineersa\HatfieldExt\ObservationalMemory\Storage\MemoryGenerationRepository;
use Ineersa\HatfieldExt\ObservationalMemory\Storage\ObservationRepository;

/**
 * Shared Reflector invocation: input assembly, complete-generation tool loop, one compression retry.
 */
final class ReflectorPipeline
{
    public function __construct(
        private readonly \Psr\Log\LoggerInterface $logger,
    ) {
    }

    /**
     * @return array{
     *   reflections: list<array{
     *     reflection_id: string,
     *     content: string,
     *     supporting_observation_ids: list<string>,
     *     supporting_observation_ids_json: string,
     *     token_count: int
     *   }>,
     *   retained_observation_ids: list<string>,
     *   rendered_text: string
     * }
     */
    public function produceCandidate(
        ExtensionApiInterface $api,
        ObservationRepository $observationRepo,
        MemoryGenerationRepository $generationRepo,
        OmSettings $settings,
        string $runId,
        string $reflectorModel,
        ?string $customInstructions,
        ?string $jobId,
        ?string $correlationId,
    ): array {
        $activeReflections = $generationRepo->listActiveReflections($runId);
        $activeObservations = $observationRepo->listActiveCandidateObservations($runId);

        if ([] === $activeReflections && [] === $activeObservations) {
            throw new ReflectorException('no_active_memory', 'Reflector invoked with empty active memory.');
        }

        $allowedReflectionIds = [];
        $activeById = [];
        $supportCounts = [];
        foreach ($activeReflections as $reflection) {
            $id = $reflection['reflection_id'];
            $allowedReflectionIds[$id] = true;
            $support = $this->decodeSupportIds($reflection['supporting_observation_ids_json']);
            $activeById[$id] = [
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

        $input = $this->buildUserInput(
            $activeReflections,
            $activeObservations,
            $supportCounts,
            $customInstructions,
            compressionAppendix: false,
        );

        $candidate = $this->invokeReflector(
            api: $api,
            settings: $settings,
            runId: $runId,
            reflectorModel: $reflectorModel,
            input: $input,
            allowedReflectionIds: $allowedReflectionIds,
            allowedObservationIds: $allowedObservationIds,
            activeById: $activeById,
            jobId: $jobId,
            correlationId: $correlationId,
        );

        $budget = $this->budgetCheck($candidate, $activeObservations, $settings);
        if ($budget['ok']) {
            return $this->finalizeCandidate($candidate, $activeObservations);
        }

        $this->logger->info('om.reflector.compression_retry', [
            'component' => 'observational_memory',
            'event_type' => 'om.reflector.compression_retry',
            'run_id' => $runId,
            'job_id' => $jobId,
            'reflection_tokens' => $budget['reflection_tokens'],
            'observation_tokens' => $budget['observation_tokens'],
        ]);

        $retryInput = $this->buildUserInput(
            $activeReflections,
            $activeObservations,
            $supportCounts,
            $customInstructions,
            compressionAppendix: true,
        );
        $retryCandidate = $this->invokeReflector(
            api: $api,
            settings: $settings,
            runId: $runId,
            reflectorModel: $reflectorModel,
            input: $retryInput,
            allowedReflectionIds: $allowedReflectionIds,
            allowedObservationIds: $allowedObservationIds,
            activeById: $activeById,
            jobId: $jobId,
            correlationId: $correlationId,
        );

        $retryBudget = $this->budgetCheck($retryCandidate, $activeObservations, $settings);
        if (!$retryBudget['ok']) {
            throw new ReflectorException('memory_budget_exceeded', \sprintf('Reflector compression retry still exceeds pools (reflections=%d/%d, observations=%d/%d).', $retryBudget['reflection_tokens'], $settings->reflectionsMaxTokens, $retryBudget['observation_tokens'], $settings->observationsMaxTokens));
        }

        return $this->finalizeCandidate($retryCandidate, $activeObservations);
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
        ?string $customInstructions,
        bool $compressionAppendix,
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
            $lines[] = '(none)';
        } else {
            foreach ($activeReflections as $reflection) {
                $lines[] = \sprintf('[%s] %s', $reflection['reflection_id'], $reflection['content']);
            }
        }

        $lines[] = '';
        $lines[] = 'CURRENT OBSERVATIONS:';
        if ([] === $activeObservations) {
            $lines[] = '(none)';
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

        if (null !== $customInstructions && '' !== trim($customInstructions)) {
            $lines[] = '';
            $lines[] = 'Additional compaction instructions:';
            $lines[] = trim($customInstructions);
        }

        if ($compressionAppendix) {
            $lines[] = '';
            $lines[] = ReflectorSystemPrompt::compressionAppendix();
        }

        $lines[] = '';
        $lines[] = 'Current local time fallback: '.(new \DateTimeImmutable('now'))->format('Y-m-d H:i');

        return implode("\n", $lines);
    }

    /**
     * @param array<string, true> $allowedReflectionIds
     * @param array<string, true> $allowedObservationIds
     * @param array<string, array{
     *   reflection_id: string,
     *   content: string,
     *   supporting_observation_ids: list<string>,
     *   supporting_observation_ids_json: string,
     *   token_count: int
     * }> $activeById
     *
     * @return array{
     *   reflections: list<array{
     *     reflection_id: string,
     *     content: string,
     *     supporting_observation_ids: list<string>,
     *     supporting_observation_ids_json: string,
     *     token_count: int
     *   }>,
     *   retained_observation_ids: list<string>
     * }
     */
    private function invokeReflector(
        ExtensionApiInterface $api,
        OmSettings $settings,
        string $runId,
        string $reflectorModel,
        string $input,
        array $allowedReflectionIds,
        array $allowedObservationIds,
        array $activeById,
        ?string $jobId,
        ?string $correlationId,
    ): array {
        $toolHandler = new RecordReflectionsToolHandler(
            runId: $runId,
            reflectorSchemaVersion: $settings->reflectorSchemaVersion,
            allowedReflectionIds: $allowedReflectionIds,
            allowedObservationIds: $allowedObservationIds,
            activeReflectionsById: $activeById,
        );

        $api->agent()->run(new AgentCallRequestDTO(
            model: $reflectorModel,
            sessionId: $runId,
            instructions: ReflectorSystemPrompt::text(),
            input: $input,
            tools: [
                new AgentToolDTO(
                    name: 'record_reflections',
                    description: 'Record the COMPLETE next active memory generation (reflections + retained observations). Call once with the complete next set. If rejected, correct and retry once. After an accepted candidate, finish without calling the tool again.',
                    parametersJsonSchema: [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['reflections', 'retained_observation_ids'],
                        'properties' => [
                            'reflections' => [
                                'type' => 'array',
                                'description' => 'COMPLETE next active reflection set (not a delta-only list).',
                                'items' => [
                                    'oneOf' => [
                                        [
                                            'type' => 'object',
                                            'additionalProperties' => false,
                                            'required' => ['retain_id'],
                                            'properties' => [
                                                'retain_id' => [
                                                    'type' => 'string',
                                                    'minLength' => 1,
                                                    'description' => 'Existing active reflection id to keep unchanged.',
                                                ],
                                            ],
                                        ],
                                        [
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
                            'retained_observation_ids' => [
                                'type' => 'array',
                                'items' => ['type' => 'string', 'minLength' => 1],
                                'description' => 'Observation ids that remain active after this generation.',
                            ],
                        ],
                    ],
                    handler: $toolHandler,
                ),
            ],
            correlationId: $jobId ?? $correlationId,
            // One initial tool round + one correction; a third tool round is rejected by AgentProcessor.
            maxToolCalls: 2,
            thinkingLevel: $settings->reflectorThinkingLevel,
        ));

        if (!$toolHandler->hasCandidate()) {
            throw new ReflectorException('tool_not_called', 'Reflector completed without a valid record_reflections call.');
        }

        return [
            'reflections' => $toolHandler->reflections(),
            'retained_observation_ids' => $toolHandler->retainedObservationIds(),
        ];
    }

    /**
     * @param array{
     *   reflections: list<array{content: string, token_count: int, reflection_id: string}>,
     *   retained_observation_ids: list<string>
     * } $candidate
     * @param list<array{observation_id: string, content: string, token_count: int, relevance: string, timestamp: string}> $activeObservations
     *
     * @return array{ok: bool, reflection_tokens: int, observation_tokens: int}
     */
    private function budgetCheck(array $candidate, array $activeObservations, OmSettings $settings): array
    {
        $reflectionTokens = 0;
        foreach ($candidate['reflections'] as $reflection) {
            $reflectionTokens += OmTokenEstimator::estimate($reflection['content']);
        }

        $byId = [];
        foreach ($activeObservations as $observation) {
            $byId[$observation['observation_id']] = $observation;
        }
        $observationTokens = 0;
        foreach ($candidate['retained_observation_ids'] as $id) {
            $observation = $byId[$id] ?? null;
            if (null === $observation) {
                continue;
            }
            $observationTokens += OmTokenEstimator::estimate($observation['content']);
        }

        $ok = $reflectionTokens <= $settings->reflectionsMaxTokens
            && $observationTokens <= $settings->observationsMaxTokens;

        return [
            'ok' => $ok,
            'reflection_tokens' => $reflectionTokens,
            'observation_tokens' => $observationTokens,
        ];
    }

    /**
     * @param array{
     *   reflections: list<array{
     *     reflection_id: string,
     *     content: string,
     *     supporting_observation_ids: list<string>,
     *     supporting_observation_ids_json: string,
     *     token_count: int
     *   }>,
     *   retained_observation_ids: list<string>
     * } $candidate
     * @param list<array{observation_id: string, content: string, relevance: string, timestamp: string, token_count: int}> $activeObservations
     *
     * @return array{
     *   reflections: list<array{
     *     reflection_id: string,
     *     content: string,
     *     supporting_observation_ids: list<string>,
     *     supporting_observation_ids_json: string,
     *     token_count: int
     *   }>,
     *   retained_observation_ids: list<string>,
     *   rendered_text: string
     * }
     */
    private function finalizeCandidate(array $candidate, array $activeObservations): array
    {
        $byId = [];
        foreach ($activeObservations as $observation) {
            $byId[$observation['observation_id']] = $observation;
        }

        $retainedObs = [];
        foreach ($candidate['retained_observation_ids'] as $id) {
            $observation = $byId[$id] ?? null;
            if (null === $observation) {
                continue;
            }
            $retainedObs[] = $observation;
        }

        $renderReflections = [];
        foreach ($candidate['reflections'] as $position => $reflection) {
            $renderReflections[] = [
                'reflection_id' => $reflection['reflection_id'],
                'content' => $reflection['content'],
                'position' => $position,
            ];
        }

        $rendered = ActiveMemoryRenderer::render($renderReflections, $retainedObs);
        if ('' === trim($rendered)) {
            throw new ReflectorException('empty_render', 'Deterministic active-memory render produced empty text.');
        }

        return [
            'reflections' => $candidate['reflections'],
            'retained_observation_ids' => $candidate['retained_observation_ids'],
            'rendered_text' => $rendered,
        ];
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
