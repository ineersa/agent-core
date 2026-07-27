<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\ObservationalMemory\Compaction;

use Ineersa\Hatfield\ExtensionApi\Agent\ExtensionAgentJobHandlerInterface;
use Ineersa\Hatfield\ExtensionApi\ExtensionApiInterface;
use Ineersa\HatfieldExt\ObservationalMemory\Observer\ObserverException;
use Ineersa\HatfieldExt\ObservationalMemory\Observer\ObserverPipeline;
use Ineersa\HatfieldExt\ObservationalMemory\Runtime\OmPaths;
use Ineersa\HatfieldExt\ObservationalMemory\Runtime\OmSettings;
use Ineersa\HatfieldExt\ObservationalMemory\Storage\CompactionRepository;
use Ineersa\HatfieldExt\ObservationalMemory\Storage\MemoryGenerationRepository;
use Ineersa\HatfieldExt\ObservationalMemory\Storage\ObservationRepository;
use Ineersa\HatfieldExt\ObservationalMemory\Storage\OmConflictException;
use Ineersa\HatfieldExt\ObservationalMemory\Storage\OmDatabaseFactory;
use Ineersa\HatfieldExt\ObservationalMemory\Support\OmIdentity;
use Psr\Log\LoggerInterface;

/**
 * Async Reflector + coverage catch-up worker for CompactRun replacement summaries.
 *
 * Replacement text is always server-rendered from the committed generation.
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
        $generationRepo = new MemoryGenerationRepository($connection);
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
                generationRepo: $generationRepo,
                settings: $settings,
                runId: $runId,
                requiredEndSeq: $requiredEndSeq,
                jobId: $jobId,
                correlationId: $correlationId,
            );

            $observations = $observationRepo->listActiveCandidateObservations($runId);
            $activeReflections = $generationRepo->listActiveReflections($runId);
            if ([] === $observations && [] === $activeReflections) {
                $this->commitDurableFailure(
                    compactionRepo: $compactionRepo,
                    requestId: $requestId,
                    runId: $runId,
                    requiredStartSeq: $requiredStartSeq,
                    requiredEndSeq: $requiredEndSeq,
                    requestFingerprint: $requestFingerprint,
                    failureCode: 'no_observations',
                    now: $now,
                    failureMetadata: ['reason' => 'No durable active memory available for reflection.'],
                );

                return;
            }

            $candidate = $observationRepo->activeCandidateSet($runId);
            $observationSetHash = $candidate['observation_set_hash'];
            $compactionRepo->freezeObservationSetHash($requestId, $observationSetHash);

            $reflectorModel = $settings->requireReflectorModel();
            $generationId = OmIdentity::compactionGenerationId(
                $requestId,
                $requestFingerprint,
                $reflectorModel,
                $settings->reflectorSchemaVersion,
            );

            $claim = $generationRepo->claimGeneration(
                generationId: $generationId,
                runId: $runId,
                triggerKind: MemoryGenerationRepository::TRIGGER_COMPACTION,
                observationSetHash: $observationSetHash,
                reflectorModel: $reflectorModel,
                reflectorSchemaVersion: $settings->reflectorSchemaVersion,
                now: $now,
                requiredStartSeq: $requiredStartSeq,
                requiredEndSeq: $requiredEndSeq,
                compactionRequestId: $requestId,
                requestFingerprint: $requestFingerprint,
            );

            if (\in_array($claim['status'], ['already_running', 'already_succeeded'], true)) {
                $existing = $generationRepo->getGeneration($generationId);
                if (null !== $existing && MemoryGenerationRepository::STATUS_SUCCEEDED === $existing['status']) {
                    $this->commitExistingGenerationSuccess(
                        compactionRepo: $compactionRepo,
                        generationRepo: $generationRepo,
                        observationRepo: $observationRepo,
                        requestId: $requestId,
                        generationId: $generationId,
                        runId: $runId,
                        requiredStartSeq: $requiredStartSeq,
                        requiredEndSeq: $requiredEndSeq,
                        requestFingerprint: $requestFingerprint,
                        observationSetHash: $observationSetHash,
                        reflectorModel: $reflectorModel,
                        reflectorSchemaVersion: $settings->reflectorSchemaVersion,
                        now: $now,
                    );

                    return;
                }

                $this->logger->info('om.compaction.generation_redelivery_wait', [
                    'component' => 'observational_memory',
                    'event_type' => 'om.compaction.generation_redelivery_wait',
                    'run_id' => $runId,
                    'request_id' => $requestId,
                    'generation_id' => $generationId,
                    'claim_status' => $claim['status'],
                ]);

                return;
            }
            if ('conflict' === $claim['status']) {
                throw new OmConflictException(\sprintf('Compaction generation claim conflict for %s.', $generationId));
            }

            $pipeline = new ReflectorPipeline($this->logger);
            $produced = $pipeline->produceCandidate(
                api: $api,
                observationRepo: $observationRepo,
                generationRepo: $generationRepo,
                settings: $settings,
                runId: $runId,
                reflectorModel: $reflectorModel,
                customInstructions: $customInstructions,
                jobId: $jobId,
                correlationId: $correlationId,
            );

            // Late timeout must reject promotion/result.
            $status = $compactionRepo->getRequestStatus($requestId);
            if (null !== $status && CompactionRepository::STATUS_TIMED_OUT === $status['status']) {
                $generationRepo->markFailed($generationId, 'timed_out', $now);
                throw new OmConflictException(\sprintf('Compaction request %s already timed out; reject late success.', $requestId));
            }

            $generationRepo->commitSucceededGeneration(
                generationId: $generationId,
                runId: $runId,
                observationSetHash: $observationSetHash,
                reflectorModel: $reflectorModel,
                reflectorSchemaVersion: $settings->reflectorSchemaVersion,
                reflections: array_map(static fn (array $r): array => [
                    'reflection_id' => $r['reflection_id'],
                    'content' => $r['content'],
                    'supporting_observation_ids_json' => $r['supporting_observation_ids_json'],
                    'token_count' => $r['token_count'],
                ], $produced['reflections']),
                retainedObservationIds: $produced['retained_observation_ids'],
                now: $now,
                compactionRequestId: $requestId,
            );

            $resultId = hash('sha256', implode('|', [$requestId, $observationSetHash, $generationId, 'success']));
            $compactionRepo->commitSuccess(
                requestId: $requestId,
                resultId: $resultId,
                runId: $runId,
                requiredStartSeq: $requiredStartSeq,
                requiredEndSeq: $requiredEndSeq,
                requiredWatermark: $requiredEndSeq,
                requestFingerprint: $requestFingerprint,
                observationSetHash: $observationSetHash,
                replacementText: $produced['rendered_text'],
                reflectorModel: $reflectorModel,
                reflectorSchemaVersion: $settings->reflectorSchemaVersion,
                reflections: [],
                now: $now,
                metadata: [
                    'generation_id' => $generationId,
                    'observation_set_hash' => $observationSetHash,
                    'request_fingerprint' => $requestFingerprint,
                    'reflection_count' => \count($produced['reflections']),
                    'retained_observation_count' => \count($produced['retained_observation_ids']),
                    'required_start_seq' => $requiredStartSeq,
                    'required_end_seq' => $requiredEndSeq,
                    'render' => 'active_memory_v1',
                ],
            );

            $this->logger->info('om.compaction.succeeded', [
                'component' => 'observational_memory',
                'event_type' => 'om.compaction.succeeded',
                'run_id' => $runId,
                'request_id' => $requestId,
                'generation_id' => $generationId,
                'reflection_count' => \count($produced['reflections']),
                'retained_observation_count' => \count($produced['retained_observation_ids']),
            ]);
        } catch (OmConflictException $e) {
            throw $e;
        } catch (ReflectorException $e) {
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
                    'reflector_failure_code' => $e->failureCode,
                ],
            );
        } catch (ObserverException $e) {
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
            // Transient/provider/process failures: allow Messenger retry.
            throw $e;
        }
    }

    /**
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

    private function commitExistingGenerationSuccess(
        CompactionRepository $compactionRepo,
        MemoryGenerationRepository $generationRepo,
        ObservationRepository $observationRepo,
        string $requestId,
        string $generationId,
        string $runId,
        int $requiredStartSeq,
        int $requiredEndSeq,
        string $requestFingerprint,
        string $observationSetHash,
        string $reflectorModel,
        string $reflectorSchemaVersion,
        string $now,
    ): void {
        $reflections = $generationRepo->listReflectionsForGeneration($generationId);
        $retainedIds = $generationRepo->listRetainedObservationIds($generationId);
        $activeObs = $observationRepo->listActiveCandidateObservations($runId);
        $byId = [];
        foreach ($activeObs as $obs) {
            $byId[$obs['observation_id']] = $obs;
        }
        $retained = [];
        foreach ($retainedIds as $id) {
            if (isset($byId[$id])) {
                $retained[] = $byId[$id];
            }
        }
        $renderReflections = [];
        foreach ($reflections as $reflection) {
            $renderReflections[] = [
                'reflection_id' => $reflection['reflection_id'],
                'content' => $reflection['content'],
                'position' => $reflection['position'],
            ];
        }
        $text = ActiveMemoryRenderer::render($renderReflections, $retained);
        if ('' === trim($text)) {
            throw new ReflectorException('empty_render', 'Redelivery render of succeeded generation produced empty text.');
        }

        $resultId = hash('sha256', implode('|', [$requestId, $observationSetHash, $generationId, 'success']));
        $compactionRepo->commitSuccess(
            requestId: $requestId,
            resultId: $resultId,
            runId: $runId,
            requiredStartSeq: $requiredStartSeq,
            requiredEndSeq: $requiredEndSeq,
            requiredWatermark: $requiredEndSeq,
            requestFingerprint: $requestFingerprint,
            observationSetHash: $observationSetHash,
            replacementText: $text,
            reflectorModel: $reflectorModel,
            reflectorSchemaVersion: $reflectorSchemaVersion,
            reflections: [],
            now: $now,
            metadata: [
                'generation_id' => $generationId,
                'observation_set_hash' => $observationSetHash,
                'request_fingerprint' => $requestFingerprint,
                'redelivery' => true,
                'render' => 'active_memory_v1',
            ],
        );
    }

    private function repairCoverage(
        ExtensionApiInterface $api,
        ObservationRepository $observationRepo,
        MemoryGenerationRepository $generationRepo,
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

        (new ObserverPipeline($this->logger))->observeThrough(
            api: $api,
            repository: $observationRepo,
            generationRepository: $generationRepo,
            settings: $settings,
            runId: $runId,
            terminalEndSeq: $requiredEndSeq,
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
}
