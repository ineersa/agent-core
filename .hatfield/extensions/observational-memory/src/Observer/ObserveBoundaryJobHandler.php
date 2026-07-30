<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\ObservationalMemory\Observer;

use Ineersa\Hatfield\ExtensionApi\Agent\ExtensionAgentJobHandlerInterface;
use Ineersa\Hatfield\ExtensionApi\Agent\ExtensionAgentJobRequestDTO;
use Ineersa\Hatfield\ExtensionApi\ExtensionApiInterface;
use Ineersa\HatfieldExt\ObservationalMemory\Runtime\OmActivityReporter;
use Ineersa\HatfieldExt\ObservationalMemory\Runtime\OmPaths;
use Ineersa\HatfieldExt\ObservationalMemory\Runtime\OmSettings;
use Ineersa\HatfieldExt\ObservationalMemory\Storage\MemoryGenerationRepository;
use Ineersa\HatfieldExt\ObservationalMemory\Storage\ObservationRepository;
use Ineersa\HatfieldExt\ObservationalMemory\Storage\OmDatabaseFactory;
use Ineersa\HatfieldExt\ObservationalMemory\Support\OmIdentity;
use Psr\Log\LoggerInterface;

/**
 * Async worker-local Observer job entrypoint.
 *
 * After all chunks for the boundary are durable, may dispatch threshold Reflector job.
 */
final readonly class ObserveBoundaryJobHandler implements ExtensionAgentJobHandlerInterface
{
    public const string REFLECT_HANDLER_ID = 'observational_memory.reflect_generation';

    public function __construct(
        private LoggerInterface $logger,
    ) {
    }

    public function handle(ExtensionApiInterface $api, array $payload, ?string $jobId, ?string $correlationId): void
    {
        $settings = OmSettings::fromApi($api);
        $runId = (string) ($payload['run_id'] ?? '');
        $terminalEndSeq = (int) ($payload['terminal_end_seq'] ?? 0);
        $terminalStatus = (string) ($payload['terminal_status'] ?? '');
        if ('' === $runId || $terminalEndSeq < 1 || '' === $terminalStatus) {
            throw new \InvalidArgumentException('ObserveBoundary job payload missing run_id/terminal_end_seq/terminal_status.');
        }

        $paths = OmPaths::fromSettings($settings, $api->getCwd());
        // Per-job DB open/migrate: OM is multi-project path-aware and must not
        // reuse a process-wide connection that could point at another CWD.
        $connection = OmDatabaseFactory::connectAndMigrate($paths->databasePath, $this->logger);
        $repository = new ObservationRepository($connection);
        $generationRepository = new MemoryGenerationRepository($connection);
        $activity = new OmActivityReporter($connection, $this->logger);
        $activityJobId = (null !== $jobId && '' !== $jobId) ? $jobId : null;

        $rendererVersion = (string) ($payload['renderer_version'] ?? $settings->rendererVersion);
        $observerSchemaVersion = (string) ($payload['observer_schema_version'] ?? $settings->observerSchemaVersion);
        $effectiveSettings = $settings->withRendererAndObserverVersions($rendererVersion, $observerSchemaVersion);

        try {
            (new ObserverPipeline($this->logger))->observeThrough(
                api: $api,
                repository: $repository,
                generationRepository: $generationRepository,
                settings: $effectiveSettings,
                runId: $runId,
                terminalEndSeq: $terminalEndSeq,
                terminalStatus: $terminalStatus,
                jobId: $jobId,
                correlationId: $correlationId,
                activity: $activity,
            );

            $this->maybeDispatchThresholdReflection(
                api: $api,
                repository: $repository,
                generationRepository: $generationRepository,
                settings: $settings,
                runId: $runId,
                jobId: $jobId,
                correlationId: $correlationId,
            );
        } finally {
            if (null !== $activityJobId) {
                $activity->clear($runId, $activityJobId);
            }
        }
    }

    private function maybeDispatchThresholdReflection(
        ExtensionApiInterface $api,
        ObservationRepository $repository,
        MemoryGenerationRepository $generationRepository,
        OmSettings $settings,
        string $runId,
        ?string $jobId,
        ?string $correlationId,
    ): void {
        $candidate = $repository->activeCandidateSet($runId);
        if ($candidate['token_count'] <= $settings->reflectAfterObservationTokens) {
            return;
        }
        if ([] === $candidate['observation_ids']) {
            return;
        }
        // Suppress re-dispatch when this exact set already has running/succeeded/failed.
        // Failed remains reclaimable by Messenger redelivery of the same job id; new
        // observe boundaries must not create another deterministic failed job forever.
        if ($generationRepository->hasTerminalOrInFlightGenerationForSet($runId, $candidate['observation_set_hash'])) {
            $this->logger->info('om.observe.threshold_suppressed', [
                'component' => 'observational_memory',
                'event_type' => 'om.observe.threshold_suppressed',
                'run_id' => $runId,
                'job_id' => $jobId,
                'correlation_id' => $correlationId,
                'observation_set_hash' => $candidate['observation_set_hash'],
                'token_count' => $candidate['token_count'],
            ]);

            return;
        }

        $model = $settings->requireModel();
        $priorActive = $generationRepository->activeGenerationId($runId);
        $generationId = OmIdentity::thresholdGenerationId(
            $runId,
            $priorActive,
            $candidate['observation_set_hash'],
            $model,
            $settings->reflectorSchemaVersion,
        );

        $reflectJobId = $generationId;
        try {
            $api->dispatchExtensionAgentJob(new ExtensionAgentJobRequestDTO(
                handlerId: self::REFLECT_HANDLER_ID,
                payload: [
                    'run_id' => $runId,
                    'trigger' => 'threshold',
                    'generation_id' => $generationId,
                    'threshold_idempotency_key' => $generationId,
                    'observation_set_hash' => $candidate['observation_set_hash'],
                    'prior_active_generation_id' => $priorActive,
                    // Stored as reflector_model for generation identity compatibility.
                    'reflector_model' => $model,
                    'reflector_schema_version' => $settings->reflectorSchemaVersion,
                    'token_count' => $candidate['token_count'],
                    // Source watermark for the exact active candidate set claimed at dispatch.
                    'required_end_seq' => $candidate['max_source_end_seq'],
                    'required_start_seq' => 1,
                ],
                jobId: $reflectJobId,
                correlationId: $runId,
            ));
        } catch (\Throwable $e) {
            // Threshold dispatch failure must not drop observation progress; Messenger will surface job failures separately.
            $this->logger->error('om.observe.threshold_dispatch_failed', [
                'component' => 'observational_memory',
                'event_type' => 'om.observe.threshold_dispatch_failed',
                'run_id' => $runId,
                'job_id' => $jobId,
                'generation_id' => $generationId,
                'exception_class' => $e::class,
            ]);
            throw $e;
        }

        $this->logger->info('om.observe.threshold_dispatched', [
            'component' => 'observational_memory',
            'event_type' => 'om.observe.threshold_dispatched',
            'run_id' => $runId,
            'job_id' => $jobId,
            'correlation_id' => $correlationId,
            'generation_id' => $generationId,
            'observation_set_hash' => $candidate['observation_set_hash'],
            'token_count' => $candidate['token_count'],
        ]);
    }
}
