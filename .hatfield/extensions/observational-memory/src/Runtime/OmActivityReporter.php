<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\ObservationalMemory\Runtime;

use Doctrine\DBAL\Connection;
use Ineersa\HatfieldExt\ObservationalMemory\Storage\ActivityRepository;
use Psr\Log\LoggerInterface;

/**
 * Optional diagnostics writer for live TUI status notices.
 *
 * SQLite status write/clear failures must never fail Observer/Reflector/Dropper
 * model work or force retries. Logs are privacy-safe (no exception messages/content).
 */
final class OmActivityReporter
{
    private readonly ActivityRepository $repository;

    public function __construct(
        Connection $connection,
        private readonly LoggerInterface $logger,
    ) {
        $this->repository = new ActivityRepository($connection);
    }

    public function set(
        string $runId,
        string $jobId,
        string $stage,
        int $currentTokens,
        ?int $targetTokens = null,
    ): void {
        try {
            $this->repository->upsert($runId, $jobId, $stage, $currentTokens, $targetTokens);
        } catch (\Throwable) {
            $this->logger->warning('om.activity.write_failed', [
                'component' => 'observational_memory',
                'event_type' => 'om.activity.write_failed',
                'run_id' => $runId,
                'job_id' => $jobId,
                'stage' => $stage,
            ]);
        }
    }

    public function clear(string $runId, string $jobId): void
    {
        try {
            $this->repository->clear($runId, $jobId);
        } catch (\Throwable) {
            $this->logger->warning('om.activity.clear_failed', [
                'component' => 'observational_memory',
                'event_type' => 'om.activity.clear_failed',
                'run_id' => $runId,
                'job_id' => $jobId,
            ]);
        }
    }
}
