<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred;

use Ineersa\AgentCore\Domain\Extension\AfterTurnCommitEventSummary;
use Ineersa\AgentCore\Domain\Run\RunStatus;

/**
 * Durable observation of one committed child turn within a deferred subagent batch (Piece 4B).
 */
final readonly class ObserveDeferredSubagentBatchChildTurnMessage
{
    /**
     * @param list<AfterTurnCommitEventSummary> $committedEvents
     */
    public function __construct(
        public string $batchLifecycleId,
        public int $batchIndex,
        public string $childRunId,
        public RunStatus $committedStatus,
        public int $turnNo,
        public array $committedEvents,
    ) {
    }
}
