<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Execution\ChildRun;

use Ineersa\AgentCore\Contract\EventStoreInterface;
use Ineersa\AgentCore\Contract\RunStoreInterface;
use Ineersa\AgentCore\Domain\Run\RunState;
use Psr\Log\LoggerInterface;

/**
 * Resolves and advances parent-run sequence numbers for committed child progress events.
 */
final class AgentChildParentSequenceCoordinator
{
    public function __construct(
        private readonly RunStoreInterface $parentRunStore,
        private readonly EventStoreInterface $eventStore,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Resolve the next sequence number for parent progress events.
     *
     * Uses the greater of parent state lastSeq and the maximum existing
     * parent event seq, so progress events never collide with events
     * appended by other writers or pending commits.
     */
    public function resolveNextProgressSeq(string $parentRunId): int
    {
        $parentState = $this->parentRunStore->get($parentRunId);
        $stateLastSeq = null !== $parentState ? $parentState->lastSeq : 0;

        // Fallback to max event seq in case state is temporarily stale
        // (e.g. mid-commit, or another writer advanced the log without
        // yet persisting the state checkpoint).
        $parentEvents = $this->eventStore->allFor($parentRunId);
        $maxEventSeq = 0;
        foreach ($parentEvents as $event) {
            if ($event->seq > $maxEventSeq) {
                $maxEventSeq = $event->seq;
            }
        }

        return max($stateLastSeq, $maxEventSeq) + 1;
    }

    /**
     * Advance the parent RunState.lastSeq to include the progress event.
     *
     * Uses compareAndSwap in a short retry loop to handle races with
     * other parent state writers.  If CAS fails and the current state
     * already covers our seq, treats it as a non-issue.  Exhausted
     * retries are logged but do not block or re-append — the progress
     * event already exists in the event log and replay will catch up.
     */
    public function advanceParentSequence(string $parentRunId, int $seq): void
    {
        $parentState = $this->parentRunStore->get($parentRunId);
        if (null === $parentState) {
            // Parent state not yet persisted — the first progress event
            // lands while the run is still initialising.  Non-fatal.
            $this->logger->warning('subagent_execution.parent_state_missing_for_seq_advance', [
                'component' => 'agent.execution',
                'event_type' => 'subagent_execution.parent_state_missing_for_seq_advance',
                'parent_run_id' => $parentRunId,
                'target_seq' => $seq,
            ]);

            return;
        }

        $maxAttempts = 3;

        for ($attempt = 0; $attempt < $maxAttempts; ++$attempt) {
            $nextState = new RunState(
                runId: $parentState->runId,
                status: $parentState->status,
                version: $parentState->version + 1,
                turnNo: $parentState->turnNo,
                lastSeq: $seq,
                isStreaming: $parentState->isStreaming,
                streamingMessage: $parentState->streamingMessage,
                pendingToolCalls: $parentState->pendingToolCalls,
                errorMessage: $parentState->errorMessage,
                messages: $parentState->messages,
                activeStepId: $parentState->activeStepId,
                retryableFailure: $parentState->retryableFailure,
            );

            $oldVersion = $parentState->version;

            if ($this->parentRunStore->compareAndSwap($nextState, $oldVersion)) {
                return;
            }

            // CAS failed — re-read in case another writer moved past us.
            $parentState = $this->parentRunStore->get($parentRunId);
            if (null === $parentState) {
                return;
            }

            if ($parentState->lastSeq >= $seq) {
                // Another writer already advanced past our seq.
                return;
            }
        }

        // Exhausted retries; event is already in the log.
        $this->logger->warning('subagent_execution.progress_seq_cas_failed', [
            'component' => 'agent.execution',
            'event_type' => 'subagent_execution.progress_seq_cas_failed',
            'parent_run_id' => $parentRunId,
            'target_seq' => $seq,
            'attempts' => $maxAttempts,
            'parent_state_last_seq' => $parentState->lastSeq,
        ]);
    }
}
