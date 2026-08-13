<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\Progress;

use Ineersa\CodingAgent\Agent\Execution\ChildRun\Contract\ChildRunBatchItemSnapshotDTO;
use Ineersa\CodingAgent\Agent\Execution\SubagentChildProgressSummary;
use Ineersa\CodingAgent\Agent\Execution\SubagentProgressParallelChildReportDTO;

/** Intermediate row assembly for deferred parallel progress snapshots. */
final readonly class DeferredSubagentBatchChildProgressBuildDTO
{
    public function __construct(
        public ChildRunBatchItemSnapshotDTO $snapshot,
        public SubagentProgressParallelChildReportDTO $report,
        public int $turnNo,
        public ?SubagentChildProgressSummary $enrichment,
    ) {
    }
}
