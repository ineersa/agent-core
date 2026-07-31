<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\ObservationalMemory\Compaction;

use Ineersa\Hatfield\ExtensionApi\Agent\AgentCallRequestDTO;
use Ineersa\Hatfield\ExtensionApi\Agent\AgentToolDTO;
use Ineersa\Hatfield\ExtensionApi\ExtensionApiInterface;
use Ineersa\HatfieldExt\ObservationalMemory\Observer\OmTokenEstimator;
use Ineersa\HatfieldExt\ObservationalMemory\Runtime\OmSettings;

/**
 * Pi-style bounded Dropper: model proposes ids; server ranks and hard-caps drops.
 */
final class DropperPipeline
{
    public function __construct(
        private readonly \Psr\Log\LoggerInterface $logger,
    ) {
    }

    /**
     * @param list<array{
     *   reflection_id: string,
     *   content: string,
     *   supporting_observation_ids: list<string>,
     *   supporting_observation_ids_json: string,
     *   token_count: int
     * }> $reflectionsForCoverage prior + new reflections used for coverage ranking
     * @param list<array{
     *   observation_id: string,
     *   content: string,
     *   relevance: string,
     *   timestamp: string,
     *   token_count: int
     * }> $activeObservations
     *
     * @return list<string> accepted drop ids (may be empty)
     */
    public function selectDrops(
        ExtensionApiInterface $api,
        OmSettings $settings,
        string $runId,
        string $model,
        array $reflectionsForCoverage,
        array $activeObservations,
        ?string $jobId,
        ?string $correlationId,
    ): array {
        if ([] === $activeObservations) {
            return [];
        }

        $targetTokens = $settings->observationsMaxTokens;
        $observationTokens = 0;
        foreach ($activeObservations as $observation) {
            $observationTokens += OmTokenEstimator::estimate($observation['content']);
        }
        $maxDropsAllowed = self::maxDropCountForPool(\count($activeObservations), $observationTokens, $targetTokens);
        if ($maxDropsAllowed <= 0) {
            $this->logger->info('om.dropper.skipped_not_over_target', [
                'component' => 'observational_memory',
                'event_type' => 'om.dropper.skipped_not_over_target',
                'run_id' => $runId,
                'job_id' => $jobId,
                'observation_tokens' => $observationTokens,
                'target_tokens' => $targetTokens,
            ]);

            return [];
        }

        $allowed = [];
        foreach ($activeObservations as $observation) {
            $allowed[$observation['observation_id']] = true;
        }

        $toolHandler = new DropObservationsToolHandler($allowed, $maxDropsAllowed);
        $input = $this->buildUserInput(
            $reflectionsForCoverage,
            $activeObservations,
            $observationTokens,
            $targetTokens,
            $maxDropsAllowed,
        );

        $api->agent()->run(new AgentCallRequestDTO(
            model: $model,
            sessionId: $runId,
            instructions: DropperSystemPrompt::text(),
            input: $input,
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
                                'items' => ['type' => 'string', 'minLength' => 1],
                                'description' => 'Active observation ids to propose for drop.',
                            ],
                            'reason' => [
                                'type' => 'string',
                                'description' => 'Optional unpersisted rationale for the proposal.',
                            ],
                        ],
                    ],
                    handler: $toolHandler,
                ),
            ],
            correlationId: $jobId ?? $correlationId,
            maxToolCalls: OmSettings::DEFAULT_AGENT_MAX_TOOL_CALLS,
        ));

        $selected = self::selectDropCandidates(
            $toolHandler->proposedIds(),
            $activeObservations,
            $reflectionsForCoverage,
            $maxDropsAllowed,
        );

        $this->logger->info('om.dropper.selected', [
            'component' => 'observational_memory',
            'event_type' => 'om.dropper.selected',
            'run_id' => $runId,
            'job_id' => $jobId,
            'max_drops_allowed' => $maxDropsAllowed,
            'proposed_count' => \count($toolHandler->proposedIds()),
            'selected_count' => \count($selected),
            'observation_tokens' => $observationTokens,
            'target_tokens' => $targetTokens,
        ]);

        return $selected;
    }

    /**
     * Pi maxDropCountForPool: min(active, max(1, ceil(tokensOverTarget / avg))).
     */
    public static function maxDropCountForPool(int $activeObservationCount, int $observationTokens, int $targetTokens): int
    {
        if ($activeObservationCount <= 0 || $observationTokens <= 0 || $targetTokens < 0) {
            return 0;
        }
        $tokensOverTarget = $observationTokens - $targetTokens;
        if ($tokensOverTarget <= 0) {
            return 0;
        }
        $average = $observationTokens / $activeObservationCount;
        if ($average <= 0.0) {
            return 0;
        }
        $estimated = (int) ceil($tokensOverTarget / $average);

        return min($activeObservationCount, max(1, $estimated));
    }

    /**
     * Rank proposed active ids: coverage strong→partial→none, relevance low→medium→high→critical,
     * timestamp oldest first, proposal order tie-break. Slice to maxDrops.
     *
     * @param list<string>                                                                                                            $proposedIds
     * @param list<array{observation_id: string, content: string, relevance: string, timestamp: string, token_count: int}>            $observations
     * @param list<array{reflection_id: string, supporting_observation_ids?: list<string>, supporting_observation_ids_json?: string}> $reflections
     *
     * @return list<string>
     */
    public static function selectDropCandidates(
        array $proposedIds,
        array $observations,
        array $reflections,
        int $maxDrops,
    ): array {
        if ($maxDrops <= 0 || [] === $proposedIds) {
            return [];
        }

        $byId = [];
        foreach ($observations as $observation) {
            $byId[$observation['observation_id']] = $observation;
        }

        $supportCounts = [];
        foreach ($reflections as $reflection) {
            $support = $reflection['supporting_observation_ids'] ?? null;
            if (!\is_array($support)) {
                $support = self::decodeSupportIdsStatic((string) ($reflection['supporting_observation_ids_json'] ?? '[]'));
            }
            $unique = [];
            foreach ($support as $obsId) {
                if (\is_string($obsId) && '' !== $obsId) {
                    $unique[$obsId] = true;
                }
            }
            foreach (array_keys($unique) as $obsId) {
                $supportCounts[$obsId] = ($supportCounts[$obsId] ?? 0) + 1;
            }
        }

        $firstIndex = [];
        foreach ($proposedIds as $i => $id) {
            if (!isset($firstIndex[$id])) {
                $firstIndex[$id] = $i;
            }
        }

        $candidates = [];
        foreach ($firstIndex as $id => $index) {
            $observation = $byId[$id] ?? null;
            if (null === $observation) {
                continue;
            }
            $candidates[] = [
                'id' => $id,
                'index' => $index,
                'observation' => $observation,
                'coverage_rank' => self::coverageDropRank($supportCounts[$id] ?? 0),
                'relevance_rank' => self::relevanceDropRank((string) $observation['relevance']),
                'timestamp_rank' => self::timestampRank((string) $observation['timestamp']),
            ];
        }

        usort($candidates, static function (array $a, array $b): int {
            $byCoverage = $a['coverage_rank'] <=> $b['coverage_rank'];
            if (0 !== $byCoverage) {
                return $byCoverage;
            }
            $byRelevance = $a['relevance_rank'] <=> $b['relevance_rank'];
            if (0 !== $byRelevance) {
                return $byRelevance;
            }
            $byTimestamp = $a['timestamp_rank'] <=> $b['timestamp_rank'];
            if (0 !== $byTimestamp) {
                return $byTimestamp;
            }

            return $a['index'] <=> $b['index'];
        });

        $selected = [];
        foreach (\array_slice($candidates, 0, $maxDrops) as $candidate) {
            $selected[] = $candidate['id'];
        }

        return $selected;
    }

    /**
     * @param list<array{reflection_id: string, content: string}>                                        $reflections
     * @param list<array{observation_id: string, content: string, relevance: string, timestamp: string}> $observations
     */
    private function buildUserInput(
        array $reflections,
        array $observations,
        int $observationTokens,
        int $targetTokens,
        int $maxDropsAllowed,
    ): string {
        $supportCounts = [];
        foreach ($reflections as $reflection) {
            $support = $reflection['supporting_observation_ids'] ?? null;
            if (!\is_array($support)) {
                $support = self::decodeSupportIdsStatic((string) ($reflection['supporting_observation_ids_json'] ?? '[]'));
            }
            $unique = [];
            foreach ($support as $obsId) {
                if (\is_string($obsId) && '' !== $obsId) {
                    $unique[$obsId] = true;
                }
            }
            foreach (array_keys($unique) as $obsId) {
                $supportCounts[$obsId] = ($supportCounts[$obsId] ?? 0) + 1;
            }
        }

        $lines = ['CURRENT REFLECTIONS:'];
        if ([] === $reflections) {
            $lines[] = '(none yet)';
        } else {
            foreach ($reflections as $reflection) {
                $lines[] = \sprintf('[%s] %s', $reflection['reflection_id'], $reflection['content']);
            }
        }

        $lines[] = '';
        $lines[] = 'CURRENT OBSERVATIONS:';
        if ([] === $observations) {
            $lines[] = '(none yet)';
        } else {
            foreach ($observations as $observation) {
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

        $tokensOver = max(0, $observationTokens - $targetTokens);
        $fullnessPercent = $targetTokens > 0
            ? (int) round(($observationTokens / $targetTokens) * 100)
            : 0;

        $lines[] = '';
        $lines[] = \sprintf(
            'Active observation pool: ~%s tokens; target: ~%s tokens; fullness against target: ~%d%%; over target by ~%s tokens.',
            number_format($observationTokens),
            number_format($targetTokens),
            $fullnessPercent,
            number_format($tokensOver),
        );
        $lines[] = \sprintf(
            'Maximum drops allowed this run: %s observation%s. This maximum is sized to move the active pool toward the target if every proposed drop is clearly safe.',
            number_format($maxDropsAllowed),
            1 === $maxDropsAllowed ? '' : 's',
        );
        $lines[] = 'This maximum is a hard upper bound, not a target. Drop fewer or none if fewer observations are clearly safe.';

        return implode("\n", $lines);
    }

    private static function coverageDropRank(int $supportCount): int
    {
        // strong=0, partial=1, none=2
        if ($supportCount >= 2) {
            return 0;
        }
        if (1 === $supportCount) {
            return 1;
        }

        return 2;
    }

    private static function relevanceDropRank(string $relevance): int
    {
        return match ($relevance) {
            'low' => 0,
            'medium' => 1,
            'high' => 2,
            'critical' => 3,
            default => 1,
        };
    }

    private static function timestampRank(string $timestamp): int|float
    {
        // Accept "YYYY-MM-DD HH:MM" local minute stamps and ATOM timestamps.
        $normalized = str_replace(' ', 'T', $timestamp);
        $parsed = strtotime($normalized);
        if (false === $parsed) {
            return \PHP_FLOAT_MAX;
        }

        return $parsed;
    }

    /**
     * @return list<string>
     */
    private static function decodeSupportIdsStatic(string $json): array
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
