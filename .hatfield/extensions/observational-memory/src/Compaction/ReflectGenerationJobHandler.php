<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\ObservationalMemory\Compaction;

use Ineersa\Hatfield\ExtensionApi\Agent\ExtensionAgentJobHandlerInterface;
use Ineersa\Hatfield\ExtensionApi\ExtensionApiInterface;
use Ineersa\HatfieldExt\ObservationalMemory\Observer\ObserveBoundaryJobHandler;
use Ineersa\HatfieldExt\ObservationalMemory\Observer\OmTokenEstimator;
use Ineersa\HatfieldExt\ObservationalMemory\Runtime\OmActivityReporter;
use Ineersa\HatfieldExt\ObservationalMemory\Runtime\OmPaths;
use Ineersa\HatfieldExt\ObservationalMemory\Runtime\OmSettings;
use Ineersa\HatfieldExt\ObservationalMemory\Storage\MemoryGenerationRepository;
use Ineersa\HatfieldExt\ObservationalMemory\Storage\ObservationRepository;
use Ineersa\HatfieldExt\ObservationalMemory\Storage\OmConflictException;
use Ineersa\HatfieldExt\ObservationalMemory\Storage\OmDatabaseFactory;
use Ineersa\HatfieldExt\ObservationalMemory\Support\OmIdentity;
use Psr\Log\LoggerInterface;

/**
 * Threshold generation worker: delta Reflector then conditional bounded Dropper.
 *
 * Handler id: observational_memory.reflect_generation
 */
final readonly class ReflectGenerationJobHandler implements ExtensionAgentJobHandlerInterface
{
    public const string HANDLER_ID = ObserveBoundaryJobHandler::REFLECT_HANDLER_ID;

    public function __construct(
        private LoggerInterface $logger,
    ) {
    }

    public function handle(ExtensionApiInterface $api, array $payload, ?string $jobId, ?string $correlationId): void
    {
        $settings = OmSettings::fromApi($api);
        $runId = (string) ($payload['run_id'] ?? '');
        $generationId = (string) ($payload['generation_id'] ?? '');
        $observationSetHash = (string) ($payload['observation_set_hash'] ?? '');
        // Payload field kept as reflector_model for generation identity compatibility.
        $model = (string) ($payload['reflector_model'] ?? $payload['model'] ?? '');
        $reflectorSchemaVersion = (string) ($payload['reflector_schema_version'] ?? '');
        $thresholdKey = (string) ($payload['threshold_idempotency_key'] ?? $generationId);
        $priorActive = $payload['prior_active_generation_id'] ?? null;
        $priorActive = \is_string($priorActive) && '' !== $priorActive ? $priorActive : null;
        $requiredEndSeq = isset($payload['required_end_seq']) ? (int) $payload['required_end_seq'] : null;
        $requiredStartSeq = isset($payload['required_start_seq']) ? (int) $payload['required_start_seq'] : 1;
        if (null !== $requiredEndSeq && $requiredEndSeq < 0) {
            throw new \InvalidArgumentException('reflect_generation required_end_seq must be non-negative.');
        }

        if ('' === $runId || '' === $generationId || '' === $observationSetHash || '' === $model || '' === $reflectorSchemaVersion) {
            throw new \InvalidArgumentException('reflect_generation payload missing identity fields.');
        }
        if ($thresholdKey !== $generationId) {
            throw new \InvalidArgumentException('threshold generation_id must equal threshold_idempotency_key.');
        }

        $expectedId = OmIdentity::thresholdGenerationId(
            $runId,
            $priorActive,
            $observationSetHash,
            $model,
            $reflectorSchemaVersion,
        );
        if ($expectedId !== $generationId) {
            throw new \InvalidArgumentException('reflect_generation generation_id does not match threshold formula.');
        }
        if ($model !== $settings->requireModel()
            || $reflectorSchemaVersion !== $settings->reflectorSchemaVersion) {
            throw new \InvalidArgumentException('reflect_generation model/schema mismatch with current settings.');
        }

        $paths = OmPaths::fromSettings($settings, $api->getCwd());
        $connection = OmDatabaseFactory::connectAndMigrate($paths->databasePath, $this->logger);
        $generationRepo = new MemoryGenerationRepository($connection);
        $observationRepo = new ObservationRepository($connection);
        $activity = new OmActivityReporter($connection, $this->logger);
        $activityJobId = (null !== $jobId && '' !== $jobId) ? $jobId : null;
        $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(\DateTimeInterface::ATOM);

        $liveCandidateForClaim = $observationRepo->activeCandidateSet($runId);
        $claimRequiredEndSeq = $liveCandidateForClaim['max_source_end_seq'];
        if ($claimRequiredEndSeq < 1 && null !== $requiredEndSeq && $requiredEndSeq > 0) {
            $claimRequiredEndSeq = $requiredEndSeq;
        }

        $claim = $generationRepo->claimGeneration(
            generationId: $generationId,
            runId: $runId,
            triggerKind: MemoryGenerationRepository::TRIGGER_THRESHOLD,
            observationSetHash: $observationSetHash,
            reflectorModel: $model,
            reflectorSchemaVersion: $reflectorSchemaVersion,
            now: $now,
            thresholdIdempotencyKey: $thresholdKey,
            requiredStartSeq: $requiredStartSeq,
            requiredEndSeq: $claimRequiredEndSeq,
        );

        if (\in_array($claim['status'], ['already_running', 'already_succeeded'], true)) {
            $this->logger->info('om.reflect.threshold_redelivery_noop', [
                'component' => 'observational_memory',
                'event_type' => 'om.reflect.threshold_redelivery_noop',
                'run_id' => $runId,
                'generation_id' => $generationId,
                'claim_status' => $claim['status'],
            ]);

            return;
        }
        if ('conflict' === $claim['status']) {
            throw new OmConflictException(\sprintf('Threshold generation claim conflict for %s.', $generationId));
        }

        try {
            $livePrior = $generationRepo->activeGenerationId($runId);
            $candidate = $observationRepo->activeCandidateSet($runId);
            $liveGenerationId = OmIdentity::thresholdGenerationId(
                $runId,
                $livePrior,
                $candidate['observation_set_hash'],
                $model,
                $reflectorSchemaVersion,
            );

            if ($candidate['token_count'] <= $settings->reflectAfterObservationTokens
                || [] === $candidate['observation_ids']) {
                $generationRepo->markSucceededNoop($generationId, $now);
                $this->logger->info('om.reflect.threshold_noop', [
                    'component' => 'observational_memory',
                    'event_type' => 'om.reflect.threshold_noop',
                    'run_id' => $runId,
                    'generation_id' => $generationId,
                    'token_count' => $candidate['token_count'],
                ]);

                return;
            }

            if ($liveGenerationId !== $generationId
                || $candidate['observation_set_hash'] !== $observationSetHash) {
                $generationRepo->markFailed($generationId, 'stale_observation_set', $now);
                $this->logger->info('om.reflect.threshold_stale', [
                    'component' => 'observational_memory',
                    'event_type' => 'om.reflect.threshold_stale',
                    'run_id' => $runId,
                    'generation_id' => $generationId,
                    'live_generation_id' => $liveGenerationId,
                    'payload_set_hash' => $observationSetHash,
                    'live_set_hash' => $candidate['observation_set_hash'],
                ]);

                return;
            }

            if (null !== $activityJobId) {
                $activity->set($runId, $activityJobId, 'reflector', (int) $candidate['token_count']);
            }

            $reflector = new ReflectorPipeline($this->logger);
            $delta = $reflector->produceDelta(
                api: $api,
                observationRepo: $observationRepo,
                generationRepo: $generationRepo,
                settings: $settings,
                runId: $runId,
                model: $model,
                jobId: $jobId,
                correlationId: $correlationId,
            );

            $priorReflections = $delta['prior_reflections'];
            $newReflections = $delta['new_reflections'];
            $activeObservations = $delta['active_observations'];

            $droppedIds = [];
            $activeObsTokens = 0;
            foreach ($activeObservations as $observation) {
                $activeObsTokens += OmTokenEstimator::estimate($observation['content']);
            }

            // Dropper only after >=1 new reflection AND pool over observations_max_tokens.
            if (\count($newReflections) >= 1 && $activeObsTokens > $settings->observationsMaxTokens) {
                if (null !== $activityJobId) {
                    $activity->set(
                        $runId,
                        $activityJobId,
                        'dropper',
                        $activeObsTokens,
                        $settings->observationsMaxTokens,
                    );
                }
                $coverageReflections = array_merge($priorReflections, $newReflections);
                $dropper = new DropperPipeline($this->logger);
                $droppedIds = $dropper->selectDrops(
                    api: $api,
                    settings: $settings,
                    runId: $runId,
                    model: $model,
                    reflectionsForCoverage: $coverageReflections,
                    activeObservations: $activeObservations,
                    jobId: $jobId,
                    correlationId: $correlationId,
                );
            }

            $dropSet = [];
            foreach ($droppedIds as $id) {
                $dropSet[$id] = true;
            }
            $retainedObservationIds = [];
            foreach ($activeObservations as $observation) {
                $id = $observation['observation_id'];
                if (!isset($dropSet[$id])) {
                    $retainedObservationIds[] = $id;
                }
            }

            // Re-check after both model stages: observation set must still match the claimed generation.
            // No SQLite transaction spans model calls; a concurrent Observer commit can invalidate this set.
            $postLivePrior = $generationRepo->activeGenerationId($runId);
            $postCandidate = $observationRepo->activeCandidateSet($runId);
            $postGenerationId = OmIdentity::thresholdGenerationId(
                $runId,
                $postLivePrior,
                $postCandidate['observation_set_hash'],
                $model,
                $reflectorSchemaVersion,
            );
            if ($postGenerationId !== $generationId
                || $postCandidate['observation_set_hash'] !== $observationSetHash) {
                $generationRepo->markFailed($generationId, 'stale_observation_set', $now);
                $this->logger->info('om.reflect.threshold_stale_after_models', [
                    'component' => 'observational_memory',
                    'event_type' => 'om.reflect.threshold_stale_after_models',
                    'run_id' => $runId,
                    'generation_id' => $generationId,
                    'live_generation_id' => $postGenerationId,
                    'payload_set_hash' => $observationSetHash,
                    'live_set_hash' => $postCandidate['observation_set_hash'],
                ]);

                return;
            }

            // Final generation = prior active reflections + new delta reflections.
            $finalReflections = array_merge($priorReflections, $newReflections);

            $generationRepo->commitSucceededGeneration(
                generationId: $generationId,
                runId: $runId,
                observationSetHash: $observationSetHash,
                reflectorModel: $model,
                reflectorSchemaVersion: $reflectorSchemaVersion,
                reflections: array_map(static fn (array $r): array => [
                    'reflection_id' => $r['reflection_id'],
                    'content' => $r['content'],
                    'supporting_observation_ids_json' => $r['supporting_observation_ids_json'],
                    'token_count' => $r['token_count'],
                ], $finalReflections),
                retainedObservationIds: $retainedObservationIds,
                now: $now,
            );

            $this->logger->info('om.reflect.threshold_succeeded', [
                'component' => 'observational_memory',
                'event_type' => 'om.reflect.threshold_succeeded',
                'run_id' => $runId,
                'generation_id' => $generationId,
                'prior_reflection_count' => \count($priorReflections),
                'new_reflection_count' => \count($newReflections),
                'dropped_observation_count' => \count($droppedIds),
                'retained_observation_count' => \count($retainedObservationIds),
            ]);
        } catch (ReflectorException $e) {
            $generationRepo->markFailed($generationId, $e->failureCode, $now);
            $this->logger->error('om.reflect.threshold_failed', [
                'component' => 'observational_memory',
                'event_type' => 'om.reflect.threshold_failed',
                'run_id' => $runId,
                'generation_id' => $generationId,
                'failure_code' => $e->failureCode,
                'exception_class' => $e::class,
            ]);

            // Durable Reflector semantic failures are terminal for this generation claim.
            return;
        } catch (OmConflictException $e) {
            throw $e;
        } catch (\RuntimeException $e) {
            $generationRepo->markFailed($generationId, 'transient_runtime', $now);
            throw $e;
        } finally {
            if (null !== $activityJobId) {
                $activity->clear($runId, $activityJobId);
            }
        }
    }
}
