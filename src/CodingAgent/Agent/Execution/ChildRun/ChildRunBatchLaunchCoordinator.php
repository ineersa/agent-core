<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Execution\ChildRun;

use Ineersa\CodingAgent\Agent\Execution\ChildRun\Port\ChildRunArtifactLifecyclePort;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Port\ChildRunProcessPort;

/**
 * Process start and Running transition after the application layer has reserved pending artifacts.
 */
final class ChildRunBatchLaunchCoordinator
{
    public function __construct(
        private readonly ChildRunProcessPort $processPort,
        private readonly ChildRunArtifactLifecyclePort $artifactLifecycle,
    ) {
    }

    /**
     * @return array<string, ChildRunBatchItemSnapshotDTO>
     */
    public function initialSnapshots(ChildRunBatchDTO $batch): array
    {
        $snapshots = [];
        foreach ($batch->children as $prepared) {
            $id = $prepared->identity;
            $snapshots[$id->childRunId] = new ChildRunBatchItemSnapshotDTO($id, false, null, '');
        }

        return $snapshots;
    }

    public function launchAll(ChildRunBatchDTO $batch): void
    {
        foreach ($batch->children as $prepared) {
            $this->processPort->start($prepared->startRunInput);
            $this->artifactLifecycle->markRunning($prepared->identity);
        }
    }
}
