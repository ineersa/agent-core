<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\ObservationalMemory\Observer;

use Ineersa\Hatfield\ExtensionApi\Agent\AgentCallRequestDTO;
use Ineersa\Hatfield\ExtensionApi\Agent\AgentToolDTO;
use Ineersa\Hatfield\ExtensionApi\ExtensionApiInterface;
use Ineersa\Hatfield\ExtensionApi\Session\SessionEventDTO;
use Ineersa\HatfieldExt\ObservationalMemory\Runtime\OmSettings;
use Ineersa\HatfieldExt\ObservationalMemory\Storage\MemoryGenerationRepository;
use Ineersa\HatfieldExt\ObservationalMemory\Storage\ObservationRepository;
use Ineersa\HatfieldExt\ObservationalMemory\Support\OmCanonicalJson;
use Psr\Log\LoggerInterface;

/**
 * Shared Observer model/render/persist pipeline for hot observe jobs and compaction catch-up.
 *
 * Chunks under floor(context_window * 0.65); multi-call accumulate; zero-obs coverage valid.
 */
final readonly class ObserverPipeline
{
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

        $observerModel = $settings->requireObserverModel();
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
                maxToolCalls: 100,
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
