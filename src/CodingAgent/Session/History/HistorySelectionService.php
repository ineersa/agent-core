<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Session\History;

use Ineersa\AgentCore\Application\Handler\RunLockManager;
use Ineersa\AgentCore\Contract\ActiveRunContextInterface;
use Ineersa\AgentCore\Contract\EventStoreInterface;
use Ineersa\AgentCore\Contract\History\HistorySelectionServiceInterface;
use Ineersa\AgentCore\Contract\Replay\RunStateRebuilderInterface;
use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Domain\Event\RunEventTypeEnum;
use Ineersa\AgentCore\Domain\Message\InvalidateRunContext;
use Ineersa\AgentCore\Domain\Run\RunState;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Positions linear history for /history selection.
 *
 * Selecting user prompt turn N is non-destructive:
 *  - Appends history_position_set with position = predecessor(N) (or 0 for first)
 *  - Rebuilds RunState for that boundary (context immediately before N)
 *  - Returns selected_prompt_turn_no = N for editor/picker UX
 *  - Forward turns remain active until a context-mutating action discards them
 *
 * Target must be a sparse human prompt; predecessor is computed over ALL retained
 * anchors so a hidden internal turn can be the correct boundary.
 */
final readonly class HistorySelectionService implements HistorySelectionServiceInterface
{
    public function __construct(
        private EventStoreInterface $eventStore,
        private RunStateRebuilderInterface $runStateRebuilder,
        private ActiveRunContextInterface $activeRunContext,
        private RunLockManager $lockManager,
        private LoggerInterface $logger,
        private HistoryProjectionStoreInterface $historyProjectionStore,
        private MessageBusInterface $commandBus,
    ) {
    }

    /**
     * @return array{rebuiltState: RunState, positionEventSeq: int, selectedPromptTurnNo: int, editorPromptText: string}
     *
     * @throws \RuntimeException
     */
    public function selectPrompt(string $runId, int $targetPromptTurnNo): array
    {
        return $this->lockManager->synchronized($runId, function () use ($runId, $targetPromptTurnNo): array {
            $snapshot = $this->historyProjectionStore->get($runId);
            $history = $snapshot->history;
            if (0 === $snapshot->lastSeq) {
                throw new \RuntimeException(\sprintf('Cannot select history for run %s: no events found.', $runId));
            }

            if (!\array_key_exists($targetPromptTurnNo, $history->promptsByTurnNo)) {
                throw new \RuntimeException(\sprintf('Cannot select history for run %s: target turn %d is not a selectable human prompt.', $runId, $targetPromptTurnNo));
            }

            $state = $this->activeRunContext->stateFor($runId);

            $previousPosition = $history->positionTurnNo;
            $positionTurnNo = $history->predecessorTurnNo($targetPromptTurnNo);
            $editorPromptText = $history->promptsByTurnNo[$targetPromptTurnNo];

            $positionEvent = new RunEvent(
                runId: $runId,
                seq: 0,
                turnNo: $positionTurnNo,
                type: RunEventTypeEnum::HistoryPositionSet->value,
                payload: [
                    'position_turn_no' => $positionTurnNo,
                    'previous_position_turn_no' => $previousPosition,
                    'selected_prompt_turn_no' => $targetPromptTurnNo,
                    'reason' => 'history_select',
                ],
                createdAt: new \DateTimeImmutable(),
            );

            // Withdraw readiness before the durable append so a crash cannot leave
            // jointly stale projections marked ready past the unread event.
            $this->activeRunContext->withdrawForCommit($runId);
            $persisted = $this->eventStore->append($positionEvent);
            $newSeq = $persisted->seq;

            $updatedHistory = new HistoryDTO(
                retainedTurnNos: $history->retainedTurnNos,
                promptsByTurnNo: $history->promptsByTurnNo,
                positionTurnNo: $positionTurnNo,
            );
            // The position event is already durable; advance inspection without a second archive scan.
            $inspectionBuilder = HistoryStreamBuilder::fromSnapshot($snapshot);
            $inspectionBuilder->apply($persisted);
            $inspectionSnapshot = $inspectionBuilder->finishSnapshot($newSeq);
            $this->historyProjectionStore->remember(
                $runId,
                new HistoryProjectionSnapshot(
                    history: $updatedHistory,
                    lastSeq: $newSeq,
                    initialPrompt: $snapshot->initialPrompt,
                    pendingHumanPrompt: $snapshot->pendingHumanPrompt,
                    ready: true,
                    eventCount: $inspectionSnapshot->eventCount,
                    sanitizedEventTail: $inspectionSnapshot->sanitizedEventTail,
                    eligibleAutoCompactionInputTokens: $inspectionSnapshot->eligibleAutoCompactionInputTokens,
                    issuedReminderKeys: $inspectionSnapshot->issuedReminderKeys,
                    appliedShellIdempotencyKeys: $inspectionSnapshot->appliedShellIdempotencyKeys,
                    runStartedLaunch: $inspectionSnapshot->runStartedLaunch,
                ),
            );

            $replayResult = $this->runStateRebuilder->rebuildAtPosition($state, $runId, $positionTurnNo);
            if (null === $replayResult->rebuiltState) {
                throw new \RuntimeException(\sprintf('Failed to rebuild state for run %s at position %d.', $runId, $positionTurnNo));
            }

            $rebuiltState = $replayResult->rebuiltState;
            if ($rebuiltState->lastSeq < $newSeq || $rebuiltState->turnNo !== $positionTurnNo) {
                $rebuiltState = $rebuiltState->with([
                    'turnNo' => $positionTurnNo,
                    'lastSeq' => max($rebuiltState->lastSeq, $newSeq),
                ]);
            }

            $this->logger->info('run_history.selected', [
                'run_id' => $runId,
                'selected_prompt_turn_no' => $targetPromptTurnNo,
                'position_turn_no' => $positionTurnNo,
                'previous_position_turn_no' => $previousPosition,
                'position_event_seq' => $newSeq,
                'component' => 'history',
                'event_type' => 'history_position_set',
            ]);

            $this->activeRunContext->remember($rebuiltState);
            $this->commandBus->dispatch(new InvalidateRunContext($runId));

            return [
                'rebuiltState' => $rebuiltState,
                'positionEventSeq' => $newSeq,
                'selectedPromptTurnNo' => $targetPromptTurnNo,
                'editorPromptText' => $editorPromptText,
            ];
        });
    }
}
