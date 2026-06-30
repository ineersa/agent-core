<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Application\Handler;

use Ineersa\AgentCore\Contract\EventStoreInterface;
use Ineersa\AgentCore\Contract\RunStoreInterface;
use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Domain\Event\RunEventTypeEnum;
use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\AgentCore\Domain\Run\TurnTreeProjector;
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
final readonly class RunRewindService
{
    public function __construct(
        private EventStoreInterface $eventStore,
        private RunStateReplayService $runStateReplayService,
        private RunStoreInterface $runStore,
        private RunLockManager $lockManager,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Rewind a run to a target turn under the run lock.
     *
     * @return array{rebuiltState: RunState, leafSetSeq: int}
     *
     * @throws \RuntimeException when the target turn does not exist,
     *                           no events are found, or persistence fails (CAS conflict)
     */
    public function rewind(string $runId, int $targetTurnNo): array
    {
        return $this->lockManager->synchronized($runId, function () use ($runId, $targetTurnNo): array {
            $events = $this->eventStore->allFor($runId);

            if ([] === $events) {
                throw new \RuntimeException(\sprintf('Cannot rewind run %s: no events found.', $runId));
            }

            // Build the turn tree to validate the target turn exists.
            $projector = new TurnTreeProjector();
            $tree = $projector->build($runId, $events);

            if (!isset($tree->nodesByTurnNo[$targetTurnNo])) {
                throw new \RuntimeException(\sprintf('Cannot rewind run %s: target turn %d does not exist in the turn tree.', $runId, $targetTurnNo));
            }

            // Read current state for CAS version.
            $state = $this->runStore->get($runId);

            if (null === $state) {
                throw new \RuntimeException(\sprintf('Cannot rewind run %s: no run state found.', $runId));
            }

            // Compute max seq from the full canonical stream.
            $maxSeq = 0;
            foreach ($events as $event) {
                if ($event->seq > $maxSeq) {
                    $maxSeq = $event->seq;
                }
            }

            $currentLeafTurnNo = $tree->currentLeafTurnNo;
            $newSeq = $maxSeq + 1;

            // Determine parent_turn_no of the target (its parent in the tree).
            $targetParentTurnNo = $tree->nodesByTurnNo[$targetTurnNo]->parentTurnNo;

            $leafSetPayload = [
                'turn_no' => $targetTurnNo,
                'previous_turn_no' => $currentLeafTurnNo,
                'parent_turn_no' => $targetParentTurnNo,
                'reason' => 'rewind',
            ];

            // Append LeafSet event.
            $leafSetEvent = new RunEvent(
                runId: $runId,
                seq: $newSeq,
                turnNo: $targetTurnNo,
                type: RunEventTypeEnum::LeafSet->value,
                payload: $leafSetPayload,
                createdAt: new \DateTimeImmutable(),
            );

            $this->eventStore->append($leafSetEvent);

            $this->logger->info('run_rewind.leaf_set_appended', [
                'run_id' => $runId,
                'target_turn_no' => $targetTurnNo,
                'previous_turn_no' => $currentLeafTurnNo,
                'leaf_set_seq' => $newSeq,
            ]);

            // Rebuild state for the target leaf.
            $replayResult = $this->runStateReplayService->rebuildForLeaf($state, $runId, $targetTurnNo);

            if (null === $replayResult->rebuiltState) {
                throw new \RuntimeException(\sprintf('Failed to rebuild state for run %s at leaf %d.', $runId, $targetTurnNo));
            }

            $rebuiltState = $replayResult->rebuiltState;

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
