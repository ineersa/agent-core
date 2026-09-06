<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\ObservationalMemory\Observer;

use Ineersa\Hatfield\ExtensionApi\Agent\AgentCallRequestDTO;
use Ineersa\Hatfield\ExtensionApi\Agent\AgentToolDTO;
use Ineersa\Hatfield\ExtensionApi\ExtensionApiInterface;
use Ineersa\Hatfield\ExtensionApi\Session\SessionEventDTO;
use Ineersa\HatfieldExt\ObservationalMemory\Runtime\OmActivityReporter;
use Ineersa\HatfieldExt\ObservationalMemory\Runtime\OmSettings;
use Ineersa\HatfieldExt\ObservationalMemory\Storage\MemoryGenerationRepository;
use Ineersa\HatfieldExt\ObservationalMemory\Storage\ObservationRepository;
use Ineersa\HatfieldExt\ObservationalMemory\Support\OmCanonicalJson;
use Ineersa\HatfieldExt\ObservationalMemory\Support\OmIdentity;
use Psr\Log\LoggerInterface;

/**
 * Shared Observer model/render/persist pipeline for async observe-boundary jobs.
 *
 * Chunks under the configured context-window ratio; multi-call accumulate; zero-obs coverage valid.
 */
final readonly class ObserverPipeline
{
    private const int MAX_TOOL_CALLS = 6;

    public function __construct(
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Observe missing coverage through terminalEndSeq using deterministic chunks/parts.
     *
     * @return array{
     *   status: 'inserted'|'noop'|'already_covered',
     *   observation_count: int,
     *   chunks_processed: int,
     *   source_start_seq: int,
     *   source_end_seq: int
     * }
     */
    public function observeThrough(
        ExtensionApiInterface $api,
        ObservationRepository $repository,
        MemoryGenerationRepository $generationRepository,
        OmSettings $settings,
        string $runId,
        int $terminalEndSeq,
        string $terminalStatus,
        ?string $jobId,
        ?string $correlationId,
        ?OmActivityReporter $activity = null,
    ): array {
        if ($terminalEndSeq < 1) {
            throw new \InvalidArgumentException(\sprintf('Invalid terminal end seq %d for run %s.', $terminalEndSeq, $runId));
        }

        $rendererVersion = $settings->rendererVersion;
        $observerSchemaVersion = $settings->observerSchemaVersion;

        $contiguousEnd = $repository->contiguousCoveredEndSeq($runId, $rendererVersion, $observerSchemaVersion);
        $sourceStartSeq = null === $contiguousEnd ? 1 : $contiguousEnd + 1;
        if ($sourceStartSeq > $terminalEndSeq) {
            $this->logger->info('om.observe.already_covered_range', [
                'component' => 'observational_memory',
                'event_type' => 'om.observe.already_covered_range',
                'run_id' => $runId,
                'job_id' => $jobId,
                'correlation_id' => $correlationId,
                'source_end_seq' => $terminalEndSeq,
            ]);

            return [
                'status' => 'already_covered',
                'observation_count' => 0,
                'chunks_processed' => 0,
                'source_start_seq' => $sourceStartSeq,
                'source_end_seq' => $terminalEndSeq,
            ];
        }

        $observerModel = $settings->requireModel();
        $contextWindow = $api->agent()->contextWindow($observerModel);
        if (null === $contextWindow || $contextWindow <= 0) {
            throw ObserverException::invalidContextWindow($contextWindow);
        }
        $envelope = $settings->observerEnvelope($contextWindow);

        /** @var list<SessionEventDTO> $events */
        $events = [];
        foreach ($api->sessionEvents()->readRange($runId, $sourceStartSeq, $terminalEndSeq) as $event) {
            if ($event instanceof SessionEventDTO) {
                $events[] = $event;
            }
        }
        if ([] === $events) {
            throw ObserverException::emptyRange($runId, $sourceStartSeq, $terminalEndSeq);
        }

        $systemPrompt = ObserverSystemPrompt::text();
        $toolSchemaText = $this->toolSchemaEstimateText();
        $fixedOverhead = OmTokenEstimator::estimate($systemPrompt) + OmTokenEstimator::estimate($toolSchemaText) + 32;

        $memoryReflections = $this->memoryReflectionLines($generationRepository, $runId);
        $memoryObservations = $this->memoryObservationLines($repository, $runId);

        $blocks = (new OmSourceBlockBuilder())->build($events);
        if ([] === $blocks) {
            // No content blocks — still advance coverage for the range with a synthetic empty part.
            $blocks = [[
                'run_id' => $runId,
                'seq' => $sourceStartSeq,
                'kind' => 'empty',
                'rendered_text' => \sprintf("[Source entry id: %s:%d]\n[empty content range %d..%d status=%s]", $runId, $sourceStartSeq, $sourceStartSeq, $terminalEndSeq, $terminalStatus),
                'source_refs' => [['run_id' => $runId, 'seq' => $sourceStartSeq]],
            ]];
        }

        $localTime = (new \DateTimeImmutable('now'))->format('Y-m-d H:i');
        $parts = (new OmChunkPacker())->pack(
            runId: $runId,
            rendererVersion: $rendererVersion,
            observerSchemaVersion: $observerSchemaVersion,
            blocks: $blocks,
            memoryReflections: $memoryReflections,
            memoryObservations: $memoryObservations,
            envelopeTokens: $envelope,
            localTimeFallback: $localTime,
            fixedOverheadTokens: $fixedOverhead,
        );
        // Packer ranges come from renderable block seqs only. Expand coverage so the
        // canonical interval [sourceStartSeq, terminalEndSeq] has no holes while
        // leaving rendered text/source_refs/digests (and observation IDs) unchanged.
        $parts = $this->normalizeChunkCoverageRanges(
            $parts,
            $runId,
            $sourceStartSeq,
            $terminalEndSeq,
            $rendererVersion,
            $observerSchemaVersion,
        );

        $totalObservations = 0;
        $processed = 0;
        $firstStart = $sourceStartSeq;
        $lastEnd = $sourceStartSeq;

        foreach ($parts as $part) {
            if ($repository->hasCompatibleCoverage($part['coverage_key'], $part['source_digest'], $part['part_digest'])) {
                ++$processed;
                $lastEnd = max($lastEnd, $part['source_end_seq']);
                continue;
            }

            $toolHandler = new RecordObservationsToolHandler(
                runId: $runId,
                observerSchemaVersion: $observerSchemaVersion,
                allowedSourceRefs: $part['source_refs'],
            );

            if (null !== $activity && null !== $jobId && '' !== $jobId) {
                $activity->set($runId, $jobId, 'observer', (int) $part['token_estimate']);
            }

            $api->agent()->run(new AgentCallRequestDTO(
                model: $observerModel,
                sessionId: $runId,
                instructions: $systemPrompt,
                input: $part['user_message'],
                tools: [
                    new AgentToolDTO(
                        name: 'record_observations',
                        description: 'Record timestamped, rated observations for the new conversation chunk. Call repeatedly with progress receipts until the chunk is covered; empty list / no call is valid when nothing is durable.',
                        parametersJsonSchema: $this->toolParametersSchema(),
                        handler: $toolHandler,
                    ),
                ],
                correlationId: $jobId ?? $correlationId,
                maxToolCalls: self::MAX_TOOL_CALLS,
            ));

            // Zero observations and/or no tool call at all is successful coverage.
            $observations = $toolHandler->collected();
            $coveredAt = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(\DateTimeInterface::ATOM);
            $boundaryKey = $part['chunk_key'];
            $result = $repository->commitChunkPartCoverage(
                coverageKey: $part['coverage_key'],
                runId: $runId,
                boundaryKey: $boundaryKey,
                sourceStartSeq: $part['source_start_seq'],
                sourceEndSeq: $part['source_end_seq'],
                chunkKey: $part['chunk_key'],
                partIndex: $part['part_index'],
                partCount: $part['part_count'],
                sourceDigest: $part['source_digest'],
                partDigest: $part['part_digest'],
                rendererVersion: $rendererVersion,
                observerSchemaVersion: $observerSchemaVersion,
                observerModel: $observerModel,
                observations: $observations,
                coveredAt: $coveredAt,
            );

            $totalObservations += $result['observation_count'];
            ++$processed;
            $firstStart = min($firstStart, $part['source_start_seq']);
            $lastEnd = max($lastEnd, $part['source_end_seq']);

            $this->logger->info('om.observe.chunk_persisted', [
                'component' => 'observational_memory',
                'event_type' => 'om.observe.chunk_persisted',
                'run_id' => $runId,
                'job_id' => $jobId,
                'correlation_id' => $correlationId,
                'status' => $result['status'],
                'observation_count' => $result['observation_count'],
                'part_index' => $part['part_index'],
                'part_count' => $part['part_count'],
                'source_start_seq' => $part['source_start_seq'],
                'source_end_seq' => $part['source_end_seq'],
                'token_estimate' => $part['token_estimate'],
                'envelope_tokens' => $envelope,
            ]);
        }

        return [
            'status' => $processed > 0 ? 'inserted' : 'noop',
            'observation_count' => $totalObservations,
            'chunks_processed' => $processed,
            'source_start_seq' => $firstStart,
            'source_end_seq' => $lastEnd,
        ];
    }

    /**
     * Expand packed chunk coverage ranges so they tile [sourceStartSeq, terminalEndSeq].
     *
     * Multipart same-seq chunks keep identical start/end on every part. Only identity
     * fields that hash the source range (chunk_key, coverage_key) are recomputed.
     *
     * @param list<array{
     *   source_start_seq: int,
     *   source_end_seq: int,
     *   part_index: int,
     *   part_count: int,
     *   source_digest: string,
     *   part_digest: string,
     *   chunk_key: string,
     *   coverage_key: string,
     *   rendered_part: string,
     *   source_refs: list<array{run_id: string, seq: int}>,
     *   user_message: string,
     *   token_estimate: int
     * }> $parts
     *
     * @return list<array{
     *   source_start_seq: int,
     *   source_end_seq: int,
     *   part_index: int,
     *   part_count: int,
     *   source_digest: string,
     *   part_digest: string,
     *   chunk_key: string,
     *   coverage_key: string,
     *   rendered_part: string,
     *   source_refs: list<array{run_id: string, seq: int}>,
     *   user_message: string,
     *   token_estimate: int
     * }>
     */
    private function normalizeChunkCoverageRanges(
        array $parts,
        string $runId,
        int $sourceStartSeq,
        int $terminalEndSeq,
        string $rendererVersion,
        string $observerSchemaVersion,
    ): array {
        if ([] === $parts) {
            return $parts;
        }

        // Group consecutive parts that form one logical chunk (same original range + part_count).
        /** @var list<array{start: int, end: int, part_count: int, indexes: list<int>}> $groups */
        $groups = [];
        foreach ($parts as $index => $part) {
            $start = (int) $part['source_start_seq'];
            $end = (int) $part['source_end_seq'];
            $partCount = (int) $part['part_count'];
            $last = [] === $groups ? null : $groups[array_key_last($groups)];
            if (null !== $last && $last['start'] === $start && $last['end'] === $end && $last['part_count'] === $partCount) {
                $groups[array_key_last($groups)]['indexes'][] = $index;
                continue;
            }
            $groups[] = [
                'start' => $start,
                'end' => $end,
                'part_count' => $partCount,
                'indexes' => [$index],
            ];
        }

        // Leading non-renderable seqs attach to the first chunk.
        $groups[0]['start'] = min($groups[0]['start'], $sourceStartSeq);

        // Internal holes between chunks attach to the next chunk (backward expand start).
        // Packer groups are monotonic (start/end non-decreasing across groups), so expanding
        // the next start down to previous_end+1 cannot invert a range.
        $groupCount = \count($groups);
        for ($i = 0; $i < $groupCount - 1; ++$i) {
            $expectedNext = $groups[$i]['end'] + 1;
            if ($groups[$i + 1]['start'] > $expectedNext) {
                $groups[$i + 1]['start'] = $expectedNext;
            }
        }

        // Trailing non-renderable seqs attach to the last chunk.
        $lastIdx = $groupCount - 1;
        $groups[$lastIdx]['end'] = max($groups[$lastIdx]['end'], $terminalEndSeq);

        foreach ($groups as $group) {
            $chunkKey = OmIdentity::chunkKey(
                $runId,
                $group['start'],
                $group['end'],
                $rendererVersion,
                $observerSchemaVersion,
                $parts[$group['indexes'][0]]['source_digest'],
                $group['part_count'],
            );
            foreach ($group['indexes'] as $partIndex) {
                $parts[$partIndex]['source_start_seq'] = $group['start'];
                $parts[$partIndex]['source_end_seq'] = $group['end'];
                $parts[$partIndex]['chunk_key'] = $chunkKey;
                $parts[$partIndex]['coverage_key'] = OmIdentity::coverageKey(
                    $chunkKey,
                    $parts[$partIndex]['part_index'],
                    $parts[$partIndex]['part_digest'],
                );
            }
        }

        return $parts;
    }

    /**
     * @return list<array{id: string, line: string, tokens: int}>
     */
    private function memoryReflectionLines(MemoryGenerationRepository $generationRepository, string $runId): array
    {
        $out = [];
        foreach ($generationRepository->listActiveReflections($runId) as $reflection) {
            $line = \sprintf('[%s] %s', $reflection['reflection_id'], $reflection['content']);
            $out[] = [
                'id' => $reflection['reflection_id'],
                'line' => $line,
                'tokens' => OmTokenEstimator::estimate($line),
            ];
        }

        return $out;
    }

    /**
     * @return list<array{id: string, line: string, tokens: int, timestamp: string, relevance: string}>
     */
    private function memoryObservationLines(ObservationRepository $repository, string $runId): array
    {
        $out = [];
        foreach ($repository->listActiveCandidateObservations($runId) as $observation) {
            $line = \sprintf(
                '[%s] %s [%s] %s',
                $observation['observation_id'],
                $observation['timestamp'],
                $observation['relevance'],
                $observation['content'],
            );
            $out[] = [
                'id' => $observation['observation_id'],
                'line' => $line,
                'tokens' => OmTokenEstimator::estimate($line),
                'timestamp' => $observation['timestamp'],
                'relevance' => $observation['relevance'],
            ];
        }

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    private function toolParametersSchema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['observations'],
            'properties' => [
                'observations' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['timestamp', 'content', 'relevance', 'source_refs'],
                        'properties' => [
                            'timestamp' => [
                                'type' => 'string',
                                'pattern' => '^[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}$',
                            ],
                            'content' => [
                                'type' => 'string',
                                'minLength' => 1,
                                'description' => 'Single-line plain prose. No markdown/tags/embedded timestamp.',
                            ],
                            'relevance' => [
                                'type' => 'string',
                                'enum' => ['low', 'medium', 'high', 'critical'],
                            ],
                            'source_refs' => [
                                'type' => 'array',
                                'minItems' => 1,
                                'items' => [
                                    'type' => 'object',
                                    'additionalProperties' => false,
                                    'required' => ['run_id', 'seq'],
                                    'properties' => [
                                        'run_id' => ['type' => 'string', 'minLength' => 1],
                                        'seq' => ['type' => 'integer', 'minimum' => 1],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }

    private function toolSchemaEstimateText(): string
    {
        return OmCanonicalJson::encode($this->toolParametersSchema());
    }
}
