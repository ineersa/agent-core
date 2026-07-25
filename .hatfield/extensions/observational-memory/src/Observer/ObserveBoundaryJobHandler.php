<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\ObservationalMemory\Observer;

use Ineersa\Hatfield\ExtensionApi\Agent\ExtensionAgentJobHandlerInterface;
use Ineersa\Hatfield\ExtensionApi\ExtensionApiInterface;
use Ineersa\HatfieldExt\ObservationalMemory\Runtime\OmPaths;
use Ineersa\HatfieldExt\ObservationalMemory\Runtime\OmSettings;
use Ineersa\HatfieldExt\ObservationalMemory\Storage\ObservationRepository;
use Ineersa\HatfieldExt\ObservationalMemory\Storage\OmDatabaseFactory;
use Psr\Log\LoggerInterface;

/**
 * Async worker-local Observer job entrypoint.
 *
 * Runs inside the Hatfield extension_agent Messenger worker with process-local
 * ExtensionApi (agent runner + session event reader).
 */
final readonly class ObserveBoundaryJobHandler implements ExtensionAgentJobHandlerInterface
{
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

        $rendererVersion = (string) ($payload['renderer_version'] ?? $settings->rendererVersion);
        $observerSchemaVersion = (string) ($payload['observer_schema_version'] ?? $settings->observerSchemaVersion);
        // Payload may pin renderer/schema versions from dispatch time without
        // reconstructing every OmSettings field (models/budgets must stay intact).
        $effectiveSettings = $settings->withRendererAndObserverVersions($rendererVersion, $observerSchemaVersion);

        $contiguousEnd = $repository->contiguousCoveredEndSeq($runId, $rendererVersion, $observerSchemaVersion);
        $sourceStartSeq = null === $contiguousEnd ? 1 : $contiguousEnd + 1;
        if ($sourceStartSeq > $terminalEndSeq) {
            $this->logger->info('om.observe.already_covered_range', [
                'component' => 'observational_memory',
                'event_type' => 'om.observe.already_covered_range',
                'run_id' => $runId,
                'job_id' => $jobId,
                'correlation_id' => $correlationId,
                'terminal_end_seq' => $terminalEndSeq,
            ]);

            return;
        }

        (new ObserverPipeline($this->logger))->observeRange(
            api: $api,
            repository: $repository,
            settings: $effectiveSettings,
            runId: $runId,
            sourceStartSeq: $sourceStartSeq,
            sourceEndSeq: $terminalEndSeq,
            terminalStatus: $terminalStatus,
            jobId: $jobId,
            correlationId: $correlationId,
        );
    }
}
