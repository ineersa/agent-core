<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Session\Rewind;

use Ineersa\AgentCore\Application\Handler\RunLockManager;
use Ineersa\AgentCore\Application\Handler\RunStateDuplicateSequenceReplayException;
use Ineersa\AgentCore\Application\Replay\ReplayEventPreparer;
use Ineersa\AgentCore\Contract\EventStoreInterface;
use Ineersa\AgentCore\Contract\Replay\RunStateRebuilderInterface;
use Ineersa\AgentCore\Contract\Rewind\RunRewindServiceInterface;
use Ineersa\AgentCore\Contract\RunStoreInterface;
use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Domain\Event\RunEventTypeEnum;
use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\CodingAgent\Session\TurnTree\TurnTreeProjector;
use Psr\Log\LoggerInterface;

/**
 * Positions linear history for /history selection.
 *
 * Selecting user prompt turn N is non-destructive:
 *  - Appends leaf_set with retained boundary = parent(N) (or 0 for root)
 *  - Rebuilds RunState for that boundary (context immediately before N)
 *  - Payload selected_prompt_turn_no = N for editor/picker UX
 *  - Forward turns remain active until a context-mutating action discards them
 */
final readonly class SessionRewindService implements RunRewindServiceInterface
{
    public function __construct(
        private EventStoreInterface $eventStore,
        private RunStateRebuilderInterface $runStateRebuilder,
        private RunStoreInterface $runStore,
        private RunLockManager $lockManager,
        private LoggerInterface $logger,
        private TurnTreeProjector $sessionProjector,
        private ReplayEventPreparer $replayEventPreparer,
    ) {
    }

    /**
     * @return array{rebuiltState: RunState, leafSetSeq: int, selectedPromptTurnNo: int, editorPromptText: string}
     *
     * @throws RunStateDuplicateSequenceReplayException
     * @throws \RuntimeException
     */
    public function rewind(string $runId, int $targetTurnNo): array
    {
        return $this->lockManager->synchronized($runId, function () use ($runId, $targetTurnNo): array {
            $events = $this->eventStore->allFor($runId);

            if ([] === $events) {
                throw new \RuntimeException(\sprintf('Cannot rewind run %s: no events found.', $runId));
            }

            $tree = $this->sessionProjector->build($runId, $events);
            $node = $tree->nodesByTurnNo[$targetTurnNo] ?? null;
            if (null === $node) {
                throw new \RuntimeException(\sprintf('Cannot rewind run %s: target turn %d is not in active history.', $runId, $targetTurnNo));
            }

            $state = $this->runStore->get($runId);
            if (null === $state) {
                throw new \RuntimeException(\sprintf('Cannot rewind run %s: no run state found.', $runId));
            }

            $duplicateSeqs = $this->replayEventPreparer->duplicateSequences($events);
            if ([] !== $duplicateSeqs) {
                throw new RunStateDuplicateSequenceReplayException(\sprintf('Cannot rewind run %s: event history contains %d duplicate sequence number(s): %s.', $runId, \count($duplicateSeqs), implode(', ', array_map('strval', \array_slice($duplicateSeqs, 0, 10)))));
            }

            $currentLeafTurnNo = $tree->currentLeafTurnNo;
            $retainedBoundary = $node->parentTurnNo ?? 0;
            $editorPromptText = $node->fullPromptText;
            if ('' === $editorPromptText && 'user' === $node->displayRole) {
                $editorPromptText = $node->title;
            }

            $leafSetEvent = new RunEvent(
                runId: $runId,
                seq: 0,
                turnNo: $retainedBoundary,
                type: RunEventTypeEnum::LeafSet->value,
                payload: [
                    'turn_no' => $retainedBoundary,
                    'previous_turn_no' => $currentLeafTurnNo,
                    'selected_prompt_turn_no' => $targetTurnNo,
                    'reason' => 'history_select',
                ],
                createdAt: new \DateTimeImmutable(),
            );

            $persistedLeafSet = $this->eventStore->append($leafSetEvent);
            $newSeq = $persistedLeafSet->seq;

            $replayResult = $this->runStateRebuilder->rebuildForLeaf($state, $runId, $retainedBoundary);
            if (null === $replayResult->rebuiltState) {
                throw new \RuntimeException(\sprintf('Failed to rebuild state for run %s at boundary %d.', $runId, $retainedBoundary));
            }

            $rebuiltState = $replayResult->rebuiltState;
            if ($rebuiltState->lastSeq < $newSeq || $rebuiltState->turnNo !== $retainedBoundary) {
                $rebuiltState = new RunState(
                    runId: $rebuiltState->runId,
                    status: $rebuiltState->status,
                    version: $rebuiltState->version,
                    turnNo: $retainedBoundary,
                    lastSeq: max($rebuiltState->lastSeq, $newSeq),
                    isStreaming: $rebuiltState->isStreaming,
                    streamingMessage: $rebuiltState->streamingMessage,
                    pendingToolCalls: $rebuiltState->pendingToolCalls,
                    errorMessage: $rebuiltState->errorMessage,
                    messages: $rebuiltState->messages,
                    activeStepId: $rebuiltState->activeStepId,
                    retryableFailure: $rebuiltState->retryableFailure,
                    retryAttempts: $rebuiltState->retryAttempts,
                    pendingHumanInputRequests: $rebuiltState->pendingHumanInputRequests,
                    model: $rebuiltState->model,
                );
            }

            $this->logger->info('run_history.selected', [
                'run_id' => $runId,
                'selected_prompt_turn_no' => $targetTurnNo,
                'retained_boundary_turn_no' => $retainedBoundary,
                'previous_turn_no' => $currentLeafTurnNo,
                'leaf_set_seq' => $newSeq,
                'component' => 'history',
                'event_type' => 'leaf_set',
            ]);

            if (!$this->runStore->compareAndSwap($rebuiltState, $state->version)) {
                throw new \RuntimeException(\sprintf('Failed to persist history-select state for run %s (CAS conflict).', $runId));
            }

            return [
                'rebuiltState' => $rebuiltState,
                'leafSetSeq' => $newSeq,
                'selectedPromptTurnNo' => $targetTurnNo,
                'editorPromptText' => $editorPromptText,
            ];
        });
    }
}
