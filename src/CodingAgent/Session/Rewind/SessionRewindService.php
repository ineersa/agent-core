<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Session\Rewind;

use Ineersa\AgentCore\Application\Handler\RunLockManager;
use Ineersa\AgentCore\Application\Handler\RunStateDuplicateSequenceReplayException;
use Ineersa\AgentCore\Application\Handler\RunStateReplayException;
use Ineersa\AgentCore\Application\Replay\ReplayEventPreparer;
use Ineersa\AgentCore\Contract\EventStoreInterface;
use Ineersa\AgentCore\Contract\Replay\RunStateRebuilderInterface;
use Ineersa\AgentCore\Contract\Rewind\RunRewindServiceInterface;
use Ineersa\AgentCore\Contract\RunStoreInterface;
use Ineersa\AgentCore\Contract\TurnTree\TurnTreeProjectorInterface;
use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Domain\Event\RunEventTypeEnum;
use Ineersa\AgentCore\Domain\Run\RunState;
use Psr\Log\LoggerInterface;

/**
 * Encapsulates the rewind-to-turn operation: append LeafSet event,
 * rebuild RunState for the target leaf, and persist — all under
 * the run lock (acquired internally).
 *
 * Consumption:
 *   $result = $rewindService->rewind($runId, $targetTurnNo);
 *   $rebuiltState = $result['rebuiltState'];
 *   $leafSetSeq = $result['leafSetSeq'];
 *
 * On success, the LeafSet event is appended, state is rebuilt and
 * persisted via compareAndSwap. The caller should emit a
 * RunLeafChanged RuntimeEvent so the TUI observes the change.
 */
final readonly class SessionRewindService implements RunRewindServiceInterface
{
    public function __construct(
        private EventStoreInterface $eventStore,
        private RunStateRebuilderInterface $runStateRebuilder,
        private RunStoreInterface $runStore,
        private RunLockManager $lockManager,
        private LoggerInterface $logger,
        private TurnTreeProjectorInterface $turnTreeProjector,
        private ReplayEventPreparer $replayEventPreparer,
    ) {
    }

    /**
     * Rewind a run to a target turn under the run lock.
     *
     * @return array{rebuiltState: RunState, leafSetSeq: int}
     *
     * @throws RunStateReplayException when persisted event history contains duplicate sequence numbers
     *                                 ({@see RunStateReplayException::REASON_DUPLICATE_SEQUENCES})
     * @throws \RuntimeException       when the target turn does not exist, no events are found,
     *                                 sequenced store is unavailable, rebuild fails, or persistence fails (CAS conflict)
     */
    public function rewind(string $runId, int $targetTurnNo): array
    {
        return $this->lockManager->synchronized($runId, function () use ($runId, $targetTurnNo): array {
            $events = $this->eventStore->allFor($runId);

            if ([] === $events) {
                throw new \RuntimeException(\sprintf('Cannot rewind run %s: no events found.', $runId));
            }

            // Build the turn tree to validate the target turn exists.
            $tree = $this->turnTreeProjector->build($runId, $events);

            if (!isset($tree->nodesByTurnNo[$targetTurnNo])) {
                throw new \RuntimeException(\sprintf('Cannot rewind run %s: target turn %d does not exist in the turn tree.', $runId, $targetTurnNo));
            }

            // Read current state for CAS version.
            $state = $this->runStore->get($runId);

            if (null === $state) {
                throw new \RuntimeException(\sprintf('Cannot rewind run %s: no run state found.', $runId));
            }

            $duplicateSeqs = $this->replayEventPreparer->duplicateSequences($events);
            if ([] !== $duplicateSeqs) {
                throw new RunStateDuplicateSequenceReplayException(\sprintf('Cannot rewind run %s: event history contains %d duplicate sequence number(s): %s.', $runId, \count($duplicateSeqs), implode(', ', array_map('strval', \array_slice($duplicateSeqs, 0, 10)))));
            }

            $currentLeafTurnNo = $tree->currentLeafTurnNo;

            // Determine parent_turn_no of the target (its parent in the tree).
            $targetParentTurnNo = $tree->nodesByTurnNo[$targetTurnNo]->parentTurnNo;

            $leafSetPayload = [
                'turn_no' => $targetTurnNo,
                'previous_turn_no' => $currentLeafTurnNo,
                'parent_turn_no' => $targetParentTurnNo,
                'reason' => 'rewind',
            ];

            $leafSetEvent = new RunEvent(
                runId: $runId,
                seq: 0,
                turnNo: $targetTurnNo,
                type: RunEventTypeEnum::LeafSet->value,
                payload: $leafSetPayload,
                createdAt: new \DateTimeImmutable(),
            );

            $persistedLeafSet = $this->eventStore->append($leafSetEvent);
            $newSeq = $persistedLeafSet->seq;

            $replayResult = $this->runStateRebuilder->rebuildForLeaf($state, $runId, $targetTurnNo);

            if (null === $replayResult->rebuiltState) {
                throw new \RuntimeException(\sprintf('Failed to rebuild state for run %s at leaf %d.', $runId, $targetTurnNo));
            }

            $rebuiltState = $replayResult->rebuiltState;

            $this->logger->info('run_rewind.leaf_set_appended', [
                'run_id' => $runId,
                'target_turn_no' => $targetTurnNo,
                'previous_turn_no' => $currentLeafTurnNo,
                'leaf_set_seq' => $newSeq,
            ]);

            // Persist the rebuilt state via compareAndSwap.
            if (!$this->runStore->compareAndSwap($rebuiltState, $state->version)) {
                throw new \RuntimeException(\sprintf('Failed to persist rewind state for run %s (CAS conflict).', $runId));
            }

            $this->logger->info('run_rewind.completed', [
                'run_id' => $runId,
                'target_turn_no' => $targetTurnNo,
                'leaf_set_seq' => $newSeq,
                'message_count' => \count($rebuiltState->messages),
                'rebuilt_status' => $rebuiltState->status->value,
            ]);

            return [
                'rebuiltState' => $rebuiltState,
                'leafSetSeq' => $newSeq,
            ];
        });
    }
}
