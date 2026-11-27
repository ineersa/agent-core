<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\Observation;

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
        public string $childRunId,
        public RunStatus $committedStatus,
        public int $turnNo,
        public array $committedEvents,
        public ?int $predecessorSequence = null,
    ) {
    }

    /**
     * Native Messenger envelopes already queued may still contain batchIndex.
     *
     * @param array{batchLifecycleId: string, childRunId: string, committedStatus: RunStatus, turnNo: int, committedEvents: list<AfterTurnCommitEventSummary>, predecessorSequence?: ?int} $data
     */
    public function __unserialize(array $data): void
    {
        $this->batchLifecycleId = $data['batchLifecycleId'];
        $this->childRunId = $data['childRunId'];
        $this->committedStatus = $data['committedStatus'];
        $this->turnNo = $data['turnNo'];
        $this->committedEvents = $data['committedEvents'];
        $this->predecessorSequence = $data['predecessorSequence'] ?? null;
    }
}
