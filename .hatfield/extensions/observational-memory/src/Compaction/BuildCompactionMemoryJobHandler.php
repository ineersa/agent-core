<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\ObservationalMemory\Compaction;

use Ineersa\Hatfield\ExtensionApi\Agent\AgentCallRequestDTO;
use Ineersa\Hatfield\ExtensionApi\Agent\AgentToolDTO;
use Ineersa\Hatfield\ExtensionApi\Agent\ExtensionAgentJobHandlerInterface;
use Ineersa\Hatfield\ExtensionApi\ExtensionApiInterface;
use Ineersa\HatfieldExt\ObservationalMemory\Observer\ObserverException;
use Ineersa\HatfieldExt\ObservationalMemory\Observer\ObserverPipeline;
use Ineersa\HatfieldExt\ObservationalMemory\Observer\OmTokenEstimator;
use Ineersa\HatfieldExt\ObservationalMemory\Runtime\OmPaths;
use Ineersa\HatfieldExt\ObservationalMemory\Runtime\OmSettings;
use Ineersa\HatfieldExt\ObservationalMemory\Storage\CompactionRepository;
use Ineersa\HatfieldExt\ObservationalMemory\Storage\ObservationRepository;
use Ineersa\HatfieldExt\ObservationalMemory\Storage\OmConflictException;
use Ineersa\HatfieldExt\ObservationalMemory\Storage\OmDatabaseFactory;
use Psr\Log\LoggerInterface;

/**
 * Async Reflector + coverage catch-up worker for CompactRun replacement summaries.
 */
final readonly class BuildCompactionMemoryJobHandler implements ExtensionAgentJobHandlerInterface
{
    public const string HANDLER_ID = 'observational_memory.build_compaction_memory';

    public function __construct(
        private LoggerInterface $logger,
    ) {
    }

    public function handle(ExtensionApiInterface $api, array $payload, ?string $jobId, ?string $correlationId): void
    {
        $settings = OmSettings::fromApi($api);
        if (!$settings->enabled) {
            return;
        }

        $requestId = (string) ($payload['request_id'] ?? '');
        $runId = (string) ($payload['run_id'] ?? '');
        $requiredStartSeq = (int) ($payload['required_start_seq'] ?? 0);
        $requiredEndSeq = (int) ($payload['required_end_seq'] ?? 0);
        $requestFingerprint = (string) ($payload['request_fingerprint'] ?? '');
        $customInstructions = isset($payload['custom_instructions']) && \is_string($payload['custom_instructions'])
            ? $payload['custom_instructions']
            : null;

        if ('' === $requestId || '' === $runId || 1 !== $requiredStartSeq || $requiredEndSeq < 0 || '' === $requestFingerprint) {
            throw new \InvalidArgumentException('BuildCompactionMemory payload missing request identity fields.');
        }

        $paths = OmPaths::fromSettings($settings, $api->getCwd());
        $connection = OmDatabaseFactory::connectAndMigrate($paths->databasePath, $this->logger);
        $compactionRepo = new CompactionRepository($connection);
        $observationRepo = new ObservationRepository($connection);
        $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(\DateTimeInterface::ATOM);

        try {
            $ensured = $compactionRepo->ensureRequest(
                requestId: $requestId,
                runId: $runId,
                requiredStartSeq: $requiredStartSeq,
                requiredEndSeq: $requiredEndSeq,
                requiredWatermark: $requiredEndSeq,
                requestFingerprint: $requestFingerprint,
                now: $now,
            );
        } catch (OmConflictException $e) {
            $this->logger->error('om.compaction.request_conflict', [
                'component' => 'observational_memory',
                'event_type' => 'om.compaction.request_conflict',
                'run_id' => $runId,
                'request_id' => $requestId,
                'exception_class' => $e::class,
            ]);
            throw $e;
        }

        if ($ensured['terminal']) {
            $this->logger->info('om.compaction.redelivery_noop', [
                'component' => 'observational_memory',
                'event_type' => 'om.compaction.redelivery_noop',
                'run_id' => $runId,
                'request_id' => $requestId,
                'status' => $ensured['status'],
            ]);

            return;
        }

        $compactionRepo->markRunning($requestId, $requestFingerprint, $now);

        try {
            $this->repairCoverage(
                api: $api,
                observationRepo: $observationRepo,
                settings: $settings,
                runId: $runId,
                requiredEndSeq: $requiredEndSeq,
                jobId: $jobId,
                correlationId: $correlationId,
            );

            $observations = $observationRepo->listObservationsForRun($runId);
            if ([] === $observations) {
                $compactionRepo->commitFailure(
                    requestId: $requestId,
                    resultId: hash('sha256', $requestId.'|failure|no_observations'),
                    runId: $runId,
                    requiredStartSeq: $requiredStartSeq,
                    requiredEndSeq: $requiredEndSeq,
                    requiredWatermark: $requiredEndSeq,
                    requestFingerprint: $requestFingerprint,
                    failureCode: 'no_observations',
                    now: $now,
                    failureMetadata: ['reason' => 'No durable observations available for reflection.'],
                );

                return;
            }

            $observationSetHash = $this->observationSetHash($observations);
            $compressionLevel = $this->chooseCompressionLevel($observations, $settings);
            $allowedIds = [];
            foreach ($observations as $observation) {
                $allowedIds[$observation['observation_id']] = true;
            }

            $input = $this->renderReflectorInput($observations, $settings, $customInstructions, $compressionLevel);
            $reflectorModel = $settings->requireReflectorModel();
            $toolHandler = new RecordReflectionsToolHandler(
                runId: $runId,
                requestId: $requestId,
                reflectorSchemaVersion: $settings->reflectorSchemaVersion,
                compressionLevel: $compressionLevel,
                allowedObservationIds: $allowedIds,
                maxReflections: $settings->maxReflections,
                reflectionContentMaxChars: $settings->reflectionContentMaxChars,
                replacementMaxChars: $settings->replacementMaxChars,
                reflectionsMaxTokens: $settings->reflectionsMaxTokens,
            );

            $api->agent()->run(new AgentCallRequestDTO(
                model: $reflectorModel,
                sessionId: $runId,
                instructions: $this->reflectorInstructions($settings, $compressionLevel),
                input: $input,
                tools: [
                    new AgentToolDTO(
                        name: 'record_reflections',
                        description: 'Record replacement summary text and durable reflections. Call exactly once.',
                        parametersJsonSchema: [
                            'type' => 'object',
                            'additionalProperties' => false,
                            'required' => ['replacement_text', 'reflections'],
                            'properties' => [
                                'replacement_text' => [
                                    'type' => 'string',
                                    'minLength' => 1,
                                    'maxLength' => $settings->replacementMaxChars,
                                ],
                                'reflections' => [
                                    'type' => 'array',
                                    'minItems' => 1,
                                    'maxItems' => $settings->maxReflections,
                                    'items' => [
                                        'type' => 'object',
                                        'additionalProperties' => false,
                                        'required' => ['content', 'supporting_observation_ids', 'compression_level'],
                                        'properties' => [
                                            'content' => [
                                                'type' => 'string',
                                                'minLength' => 1,
                                                'maxLength' => $settings->reflectionContentMaxChars,
                                            ],
                                            'supporting_observation_ids' => [
                                                'type' => 'array',
                                                'minItems' => 1,
                                                'items' => ['type' => 'string', 'minLength' => 1],
                                            ],
                                            'compression_level' => [
                                                'type' => 'integer',
                                                'minimum' => 0,
                                                'maximum' => 1,
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
                $compactionRepo->commitFailure(
                    requestId: $requestId,
                    resultId: hash('sha256', $requestId.'|failure|tool_not_called'),
                    runId: $runId,
                    requiredStartSeq: $requiredStartSeq,
                    requiredEndSeq: $requiredEndSeq,
                    requiredWatermark: $requiredEndSeq,
                    requestFingerprint: $requestFingerprint,
                    failureCode: 'reflector_tool_not_called',
                    now: $now,
                );

                return;
            }

            $replacement = (string) $toolHandler->replacementText();
            $reflections = $toolHandler->reflections();
            $resultId = hash('sha256', implode('|', [$requestId, $observationSetHash, 'success']));

            $compactionRepo->commitSuccess(
                requestId: $requestId,
                resultId: $resultId,
                runId: $runId,
                requiredStartSeq: $requiredStartSeq,
                requiredEndSeq: $requiredEndSeq,
                requiredWatermark: $requiredEndSeq,
                requestFingerprint: $requestFingerprint,
                observationSetHash: $observationSetHash,
                replacementText: $replacement,
                reflectorModel: $reflectorModel,
                reflectorSchemaVersion: $settings->reflectorSchemaVersion,
                reflections: $reflections,
                now: $now,
                metadata: [
                    'compression_level' => $compressionLevel,
                    'observation_count' => \count($observations),
                    'reflection_count' => \count($reflections),
                    'required_start_seq' => $requiredStartSeq,
                    'required_end_seq' => $requiredEndSeq,
                ],
            );

            $this->logger->info('om.compaction.succeeded', [
                'component' => 'observational_memory',
                'event_type' => 'om.compaction.succeeded',
                'run_id' => $runId,
                'request_id' => $requestId,
                'observation_count' => \count($observations),
                'reflection_count' => \count($reflections),
                'compression_level' => $compressionLevel,
            ]);
        } catch (OmConflictException $e) {
            throw $e;
        } catch (ObserverException $e) {
            // Typed durable Observer failures: persist once so the waiting CompactRun
            // hook can cancel immediately without waiting for timeout/retry.
            $this->commitDurableFailure(
                compactionRepo: $compactionRepo,
                requestId: $requestId,
                runId: $runId,
                requiredStartSeq: $requiredStartSeq,
                requiredEndSeq: $requiredEndSeq,
                requestFingerprint: $requestFingerprint,
                failureCode: $e->failureCode,
                now: $now,
                failureMetadata: [
                    'exception_class' => $e::class,
                    'observer_failure_code' => $e->failureCode,
                ],
            );
        } catch (\InvalidArgumentException $e) {
            $this->commitDurableFailure(
                compactionRepo: $compactionRepo,
                requestId: $requestId,
                runId: $runId,
                requiredStartSeq: $requiredStartSeq,
                requiredEndSeq: $requiredEndSeq,
                requestFingerprint: $requestFingerprint,
                failureCode: 'invalid_payload',
                now: $now,
                failureMetadata: ['exception_class' => $e::class],
            );
        } catch (\RuntimeException $e) {
            // Transient/provider/process failures: allow Messenger retry; CompactRun
            // hook timeout remains the safety valve if no durable failure row lands.
            throw $e;
        }
    }

    /**
     * Persist a durable failed result for the waiting CompactRun hook.
     *
     * Intentionally does NOT swallow secondary persistence failures: if
     * commitFailure itself throws (SQLite busy, disk full, schema conflict),
     * the exception must propagate so Messenger can retry. Swallowing would
     * ACK the job with the request still "running" and leave CompactRun
     * waiting until wait_timeout_seconds, which hides storage outages and
     * prevents recovery. Prefer retry/timeout over silent ACK.
     *
     * @param array<string, scalar|null> $failureMetadata
     */
    private function commitDurableFailure(
        CompactionRepository $compactionRepo,
        string $requestId,
        string $runId,
        int $requiredStartSeq,
        int $requiredEndSeq,
        string $requestFingerprint,
        string $failureCode,
        string $now,
        array $failureMetadata = [],
    ): void {
        $compactionRepo->commitFailure(
            requestId: $requestId,
            resultId: hash('sha256', $requestId.'|failure|'.$failureCode),
            runId: $runId,
            requiredStartSeq: $requiredStartSeq,
            requiredEndSeq: $requiredEndSeq,
            requiredWatermark: $requiredEndSeq,
            requestFingerprint: $requestFingerprint,
            failureCode: $failureCode,
            now: $now,
            failureMetadata: $failureMetadata,
        );
    }

    private function repairCoverage(
        ExtensionApiInterface $api,
        ObservationRepository $observationRepo,
        OmSettings $settings,
        string $runId,
        int $requiredEndSeq,
        ?string $jobId,
        ?string $correlationId,
    ): void {
        if ($requiredEndSeq < 1) {
            return;
        }

        $contiguousEnd = $observationRepo->contiguousCoveredEndSeq(
            $runId,
            $settings->rendererVersion,
            $settings->observerSchemaVersion,
        );
        $missingStart = null === $contiguousEnd ? 1 : $contiguousEnd + 1;
        if ($missingStart > $requiredEndSeq) {
            return;
        }

        (new ObserverPipeline($this->logger))->observeRange(
            api: $api,
            repository: $observationRepo,
            settings: $settings,
            runId: $runId,
            sourceStartSeq: $missingStart,
            sourceEndSeq: $requiredEndSeq,
            terminalStatus: 'compaction_catchup',
            jobId: $jobId,
            correlationId: $correlationId,
        );

        $after = $observationRepo->contiguousCoveredEndSeq(
            $runId,
            $settings->rendererVersion,
            $settings->observerSchemaVersion,
        );
        if (null === $after || $after < $requiredEndSeq) {
            throw new \RuntimeException(\sprintf('Coverage catch-up incomplete for run %s: contiguous end %s < required %d.', $runId, null === $after ? 'null' : (string) $after, $requiredEndSeq));
        }
    }

    /**
     * @param list<array{token_count: int}> $observations
     */
    private function chooseCompressionLevel(array $observations, OmSettings $settings): int
    {
        $tokens = 0;
        foreach ($observations as $observation) {
            $tokens += (int) $observation['token_count'];
        }

        // Level 1 when observation pool pressure is high.
        return $tokens >= (int) floor($settings->observationsMaxTokens * 0.7) ? 1 : 0;
    }

    /**
     * @param list<array{
     *   observation_id: string,
     *   content: string,
     *   relevance: int,
     *   token_count: int,
     *   source_start_seq: int,
     *   source_end_seq: int
     * }> $observations
     */
    private function renderReflectorInput(
        array $observations,
        OmSettings $settings,
        ?string $customInstructions,
        int $compressionLevel,
    ): string {
        // Deterministic pool ordering: relevance desc, then source range, then id.
        $pool = $observations;
        usort($pool, static function (array $a, array $b): int {
            $rel = ((int) $b['relevance']) <=> ((int) $a['relevance']);
            if (0 !== $rel) {
                return $rel;
            }
            $start = ((int) $a['source_start_seq']) <=> ((int) $b['source_start_seq']);
            if (0 !== $start) {
                return $start;
            }
            $end = ((int) $a['source_end_seq']) <=> ((int) $b['source_end_seq']);
            if (0 !== $end) {
                return $end;
            }

            return strcmp((string) $a['observation_id'], (string) $b['observation_id']);
        });

        // Level 1 keeps high-signal records only and shortens content previews.
        if (1 === $compressionLevel) {
            $pool = array_values(array_filter(
                $pool,
                static fn (array $observation): bool => ((int) $observation['relevance']) >= 50,
            ));
            if ([] === $pool) {
                $pool = \array_slice($observations, 0, min(8, \count($observations)));
            }
        }

        $lines = [
            'Compression level: '.$compressionLevel,
            'Observation count: '.\count($pool),
        ];
        if (null !== $customInstructions && '' !== trim($customInstructions)) {
            $lines[] = 'Additional instructions: '.trim($customInstructions);
        }
        $lines[] = 'Observations:';

        $budget = $settings->reflectorInputBudgetTokens;
        $used = OmTokenEstimator::estimate(implode("\n", $lines));
        foreach ($pool as $observation) {
            $content = (string) $observation['content'];
            if (1 === $compressionLevel && mb_strlen($content, 'UTF-8') > 240) {
                $content = mb_substr($content, 0, 240, 'UTF-8').'…';
            }
            $chunk = \sprintf(
                "- id=%s relevance=%d range=%d..%d tokens=%d\n  %s",
                $observation['observation_id'],
                $observation['relevance'],
                $observation['source_start_seq'],
                $observation['source_end_seq'],
                $observation['token_count'],
                $content,
            );
            $chunkTokens = OmTokenEstimator::estimate($chunk);
            if ($used + $chunkTokens > $budget) {
                $lines[] = '... truncated for reflector input budget ...';
                break;
            }
            $lines[] = $chunk;
            $used += $chunkTokens;
        }

        return implode("\n", $lines);
    }

    /**
     * @param list<array{observation_id: string, content_hash: string}> $observations
     */
    private function observationSetHash(array $observations): string
    {
        $parts = [];
        foreach ($observations as $observation) {
            $parts[] = $observation['observation_id'].':'.$observation['content_hash'];
        }
        sort($parts, \SORT_STRING);

        return hash('sha256', implode('|', $parts));
    }

    private function reflectorInstructions(OmSettings $settings, int $compressionLevel): string
    {
        $maxReflections = $settings->maxReflections;
        $contentMax = $settings->reflectionContentMaxChars;
        $replacementMax = $settings->replacementMaxChars;

        return <<<PROMPT
You are the Observational Memory Reflector for Hatfield.

Produce a compact replacement summary for older conversation context and durable reflections.
Call the record_reflections tool exactly once.

Rules:
- replacement_text must be non-empty and <= {$replacementMax} characters.
- Provide 1..{$maxReflections} reflections (at least one is required).
- Each reflection content must be non-empty and <= {$contentMax} characters.
- compression_level must be {$compressionLevel} for this request (0 or 1 only).
- Every reflection must cite supporting_observation_ids from the provided observation id list only.
- Prefer durable decisions, constraints, identities, and unresolved questions.
- Do not invent observations or include secrets/credentials.
PROMPT;
    }
}
