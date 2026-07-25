<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\ObservationalMemory\Observer;

use Ineersa\Hatfield\ExtensionApi\Agent\AgentCallRequestDTO;
use Ineersa\Hatfield\ExtensionApi\Agent\AgentToolDTO;
use Ineersa\Hatfield\ExtensionApi\ExtensionApiInterface;
use Ineersa\Hatfield\ExtensionApi\Session\SessionEventDTO;
use Ineersa\HatfieldExt\ObservationalMemory\Runtime\OmSettings;
use Ineersa\HatfieldExt\ObservationalMemory\Storage\ObservationRepository;
use Psr\Log\LoggerInterface;

/**
 * Shared Observer model/render/persist pipeline for hot observe jobs and compaction catch-up.
 */
final readonly class ObserverPipeline
{
    public function __construct(
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Observe one contiguous missing range and advance coverage.
     *
     * Requires an explicit record_observations tool invocation (including empty list).
     *
     * @return array{
     *   status: 'inserted'|'noop'|'already_covered',
     *   observation_count: int,
     *   source_start_seq: int,
     *   source_end_seq: int
     * }
     */
    public function observeRange(
        ExtensionApiInterface $api,
        ObservationRepository $repository,
        OmSettings $settings,
        string $runId,
        int $sourceStartSeq,
        int $sourceEndSeq,
        string $terminalStatus,
        ?string $jobId,
        ?string $correlationId,
    ): array {
        if ($sourceStartSeq < 1 || $sourceEndSeq < $sourceStartSeq) {
            throw new \InvalidArgumentException(\sprintf('Invalid observe range %d..%d for run %s.', $sourceStartSeq, $sourceEndSeq, $runId));
        }

        $rendererVersion = $settings->rendererVersion;
        $observerSchemaVersion = $settings->observerSchemaVersion;

        $contiguousEnd = $repository->contiguousCoveredEndSeq($runId, $rendererVersion, $observerSchemaVersion);
        if (null !== $contiguousEnd && $contiguousEnd >= $sourceEndSeq) {
            $this->logger->info('om.observe.already_covered_range', [
                'component' => 'observational_memory',
                'event_type' => 'om.observe.already_covered_range',
                'run_id' => $runId,
                'job_id' => $jobId,
                'correlation_id' => $correlationId,
                'source_end_seq' => $sourceEndSeq,
            ]);

            return [
                'status' => 'already_covered',
                'observation_count' => 0,
                'source_start_seq' => $sourceStartSeq,
                'source_end_seq' => $sourceEndSeq,
            ];
        }

        /** @var list<SessionEventDTO> $events */
        $events = [];
        foreach ($api->sessionEvents()->readRange($runId, $sourceStartSeq, $sourceEndSeq) as $event) {
            if ($event instanceof SessionEventDTO) {
                $events[] = $event;
            }
        }

        $renderer = new OmInteractionRenderer();
        $rendered = $renderer->render(
            runId: $runId,
            events: $events,
            terminalEndSeq: $sourceEndSeq,
            terminalStatus: $terminalStatus,
            rendererVersion: $rendererVersion,
            toolResultMaxChars: $settings->toolResultMaxChars,
            inputBudgetTokens: $settings->observerInputBudgetTokens,
        );

        if (($rendered['token_estimate'] ?? 0) > $settings->observerInputBudgetTokens) {
            // Renderer already profile-bounds; treat residual over-budget as actionable failure.
            throw new \RuntimeException(\sprintf('Observer input still exceeds budget after rendering (%d > %d).', (int) $rendered['token_estimate'], $settings->observerInputBudgetTokens));
        }

        $coverageKey = hash('sha256', implode('|', [
            $runId,
            $rendered['boundary_key'],
            $rendererVersion,
            $observerSchemaVersion,
        ]));

        if ($repository->hasCompatibleCoverage($coverageKey, $rendered['source_digest'])) {
            $this->logger->info('om.observe.coverage_noop', [
                'component' => 'observational_memory',
                'event_type' => 'om.observe.coverage_noop',
                'run_id' => $runId,
                'job_id' => $jobId,
                'correlation_id' => $correlationId,
            ]);

            return [
                'status' => 'noop',
                'observation_count' => 0,
                'source_start_seq' => $rendered['source_start_seq'],
                'source_end_seq' => $rendered['source_end_seq'],
            ];
        }

        $observerModel = $settings->requireObserverModel();
        $toolHandler = new RecordObservationsToolHandler(
            runId: $runId,
            boundaryKey: $rendered['boundary_key'],
            observerSchemaVersion: $observerSchemaVersion,
            sourceStartSeq: $rendered['source_start_seq'],
            sourceEndSeq: $rendered['source_end_seq'],
            allowedSourceRefs: $rendered['source_refs'],
            maxObservations: $settings->maxObservations,
            contentMaxChars: $settings->contentMaxChars,
        );

        $api->agent()->run(new AgentCallRequestDTO(
            model: $observerModel,
            sessionId: $runId,
            instructions: $this->observerInstructions(
                maxObservations: $settings->maxObservations,
                contentMaxChars: $settings->contentMaxChars,
                sourceStartSeq: $rendered['source_start_seq'],
                sourceEndSeq: $rendered['source_end_seq'],
            ),
            input: $rendered['text'],
            tools: [
                new AgentToolDTO(
                    name: 'record_observations',
                    description: 'Record durable observations for the completed interaction. Call exactly once with validated observations (may be empty list).',
                    parametersJsonSchema: [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['observations'],
                        'properties' => [
                            'observations' => [
                                'type' => 'array',
                                'maxItems' => $settings->maxObservations,
                                'items' => [
                                    'type' => 'object',
                                    'additionalProperties' => false,
                                    'required' => ['content', 'relevance', 'source_refs'],
                                    'properties' => [
                                        'content' => [
                                            'type' => 'string',
                                            'minLength' => 1,
                                            'maxLength' => $settings->contentMaxChars,
                                        ],
                                        'relevance' => [
                                            'type' => 'integer',
                                            'minimum' => 0,
                                            'maximum' => 100,
                                        ],
                                        'source_refs' => [
                                            'type' => 'array',
                                            'minItems' => 1,
                                            'items' => [
                                                'type' => 'object',
                                                'additionalProperties' => false,
                                                'required' => ['run_id', 'seq'],
                                                'properties' => [
                                                    'run_id' => ['type' => 'string'],
                                                    'seq' => ['type' => 'integer', 'minimum' => 1],
                                                ],
                                            ],
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
            maxToolCalls: 3,
        ));

        if (!$toolHandler->hasRecorded()) {
            throw new \RuntimeException('Observer model did not call record_observations; coverage not advanced.');
        }

        $observations = $toolHandler->collected();
        $coveredAt = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(\DateTimeInterface::ATOM);
        $result = $repository->commitBoundaryCoverage(
            coverageKey: $coverageKey,
            runId: $runId,
            boundaryKey: $rendered['boundary_key'],
            sourceStartSeq: $rendered['source_start_seq'],
            sourceEndSeq: $rendered['source_end_seq'],
            sourceDigest: $rendered['source_digest'],
            rendererVersion: $rendererVersion,
            observerSchemaVersion: $observerSchemaVersion,
            observerModel: $observerModel,
            observations: $observations,
            coveredAt: $coveredAt,
        );

        $this->logger->info('om.observe.persisted', [
            'component' => 'observational_memory',
            'event_type' => 'om.observe.persisted',
            'run_id' => $runId,
            'job_id' => $jobId,
            'correlation_id' => $correlationId,
            'status' => $result['status'],
            'observation_count' => $result['observation_count'],
            'render_profile' => $rendered['profile'],
            'token_estimate' => $rendered['token_estimate'],
            'source_start_seq' => $rendered['source_start_seq'],
            'source_end_seq' => $rendered['source_end_seq'],
        ]);

        return [
            'status' => $result['status'],
            'observation_count' => $result['observation_count'],
            'source_start_seq' => $rendered['source_start_seq'],
            'source_end_seq' => $rendered['source_end_seq'],
        ];
    }

    private function observerInstructions(
        int $maxObservations,
        int $contentMaxChars,
        int $sourceStartSeq,
        int $sourceEndSeq,
    ): string {
        return <<<PROMPT
You are the Observational Memory Observer for Hatfield.

Extract durable, high-signal observations from the completed interaction.
Call the record_observations tool exactly once.
If nothing durable is worth storing, call it with an empty observations list.

Rules:
- Max {$maxObservations} observations.
- Each content must be non-empty and <= {$contentMaxChars} characters.
- relevance is an integer 0..100.
- Every observation must cite source_refs as {run_id, seq} pairs from the provided interaction only.
- Allowed sequence range is {$sourceStartSeq}..{$sourceEndSeq}.
- Prefer durable facts, decisions, constraints, file/path identities, and unresolved questions.
- Do not invent events outside the provided interaction.
- Do not include secrets, credentials, or raw oversized tool dumps.
PROMPT;
    }
}
