<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Execution\Subagent\ChildRun\Deferred;

use Ineersa\AgentCore\Domain\Extension\AfterTurnCommitEventSummary;

/**
 * Durable cross-process observation of one child RunCommit batch (Piece 3B1).
 *
 * Handled on agent.command.bus / run_control transport. Parent progress and
 * deferred tool completion are wired in Piece 3B2.
 */
final readonly class ObserveDeferredSingleSubagentChildTurnMessage
{
    /**
     * @param list<AfterTurnCommitEventSummary> $committedEvents
     */
    public function __construct(
        public string $lifecycleId,
        public string $childRunId,
        public string $committedStatus,
        public int $turnNo,
        public array $committedEvents,
    ) {
        foreach ($committedEvents as $event) {
            if (!$event instanceof AfterTurnCommitEventSummary) {
                throw new \InvalidArgumentException('committedEvents must be a list of AfterTurnCommitEventSummary.');
            }
        }
    }
}
