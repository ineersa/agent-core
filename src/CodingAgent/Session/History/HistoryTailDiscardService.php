<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Session\History;

use Ineersa\AgentCore\Application\Handler\RunLockManager;
use Ineersa\AgentCore\Contract\ActiveRunContextInterface;
use Ineersa\AgentCore\Contract\EventStoreInterface;
use Ineersa\AgentCore\Contract\History\HistoryTailDiscardInterface;
use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Domain\Event\RunEventTypeEnum;
use Ineersa\AgentCore\Domain\Message\AbstractAgentBusMessage;
use Ineersa\AgentCore\Domain\Message\AdvanceRun;
use Ineersa\AgentCore\Domain\Message\ApplyCommand;
use Ineersa\AgentCore\Domain\Message\ApplyShellCommand;
use Ineersa\AgentCore\Domain\Message\CompactRun;
use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Psr\Log\LoggerInterface;

/**
 * Appends history_tail_discarded when a context-mutating message would diverge
 * while active turns exist after the current selected tip.
 *
 * Shared choke point used by RunMessageProcessor before handlers run.
 * An actual discard also clears Astra reasoning_baseline so transitions
 * anchored on discarded forward history cannot suppress the selected effort.
 *
 * Ordinary runtime uses the shared history projection; it does not scan the
 * archive on every AdvanceRun.
 */
final readonly class HistoryTailDiscardService implements HistoryTailDiscardInterface
{
    private const MUTATING_COMMAND_KINDS = [
        'follow_up',
        'steer',
        'append_message',
        'compact',
    ];

    public function __construct(
        private EventStoreInterface $eventStore,
        private HistoryProjectionStoreInterface $historyProjectionStore,
        private HatfieldSessionStore $sessionMetadataStore,
        private LoggerInterface $logger,
        private ActiveRunContextInterface $activeRunContext,
        private RunLockManager $runLockManager,
    ) {
    }

    public function isContextMutatingMessage(AbstractAgentBusMessage $message): bool
    {
        if ($message instanceof AdvanceRun || $message instanceof ApplyShellCommand || $message instanceof CompactRun) {
            return true;
        }

        if ($message instanceof ApplyCommand) {
            return \in_array($message->kind, self::MUTATING_COMMAND_KINDS, true);
        }

        return false;
    }

    /**
     * When active history has turns after the current tip, append discard marker.
     * Returns updated lastSeq (and whether a discard was written).
     *
     * @return array{discarded: bool, lastSeq: int}
     */
    public function discardForwardTailIfNeeded(string $runId, RunState $state): array
    {
        return $this->runLockManager->synchronized($runId, function () use ($runId, $state): array {
            $snapshot = $this->historyProjectionStore->get($runId);
            $active = $snapshot->history->retainedTurnNos;
            if ([] === $active) {
                return ['discarded' => false, 'lastSeq' => $state->lastSeq];
            }

            // Invalid / non-retained current state must not fabricate a discard.
            $tip = $state->turnNo;
            if (0 !== $tip && !\in_array($tip, $active, true)) {
                return ['discarded' => false, 'lastSeq' => $state->lastSeq];
            }

            $orderedTip = $active[array_key_last($active)];
            if ($tip >= $orderedTip) {
                return ['discarded' => false, 'lastSeq' => $state->lastSeq];
            }

            $discardEvent = new RunEvent(
                runId: $runId,
                seq: 0,
                turnNo: max(0, $tip),
                type: RunEventTypeEnum::HistoryTailDiscarded->value,
                payload: [
                    'after_turn_no' => $tip,
                    'reason' => 'mutate_behind_tip',
                ],
                createdAt: new \DateTimeImmutable(),
            );

            // Withdraw readiness before the durable append so a crash cannot leave
            // jointly stale projections marked ready past the unread event.
            $this->activeRunContext->withdrawForCommit($runId);
            $persisted = $this->eventStore->append($discardEvent);

            $updatedHistory = $this->applyDiscardToHistory($snapshot->history, $tip);
            $inspectionBuilder = HistoryStreamBuilder::fromSnapshot($snapshot);
            $inspectionBuilder->apply($persisted);
            $inspectionSnapshot = $inspectionBuilder->finishSnapshot($persisted->seq);
            $this->historyProjectionStore->remember(
                $runId,
                new HistoryProjectionSnapshot(
                    history: $updatedHistory,
                    lastSeq: $persisted->seq,
                    initialPrompt: null,
                    pendingHumanPrompt: null,
                    ready: true,
                    eventCount: $inspectionSnapshot->eventCount,
                    sanitizedEventTail: $inspectionSnapshot->sanitizedEventTail,
                    eligibleAutoCompactionInputTokens: $inspectionSnapshot->eligibleAutoCompactionInputTokens,
                    issuedReminderKeys: $inspectionSnapshot->issuedReminderKeys,
                    appliedShellIdempotencyKeys: $inspectionSnapshot->appliedShellIdempotencyKeys,
                    runStartedLaunch: $inspectionSnapshot->runStartedLaunch,
                ),
            );

            // Drop transitions keyed to the discarded forward tail so the next
            // request re-establishes the still-selected effort as baseline.
            $this->sessionMetadataStore->resetReasoningBaseline($runId);

            $this->logger->info('history_tail_discarded.appended', [
                'run_id' => $runId,
                'after_turn_no' => $tip,
                'discard_seq' => $persisted->seq,
                'component' => 'history',
                'event_type' => 'history_tail_discarded',
            ]);

            return ['discarded' => true, 'lastSeq' => $persisted->seq];
        });
    }

    private function applyDiscardToHistory(HistoryDTO $history, int $afterTurnNo): HistoryDTO
    {
        $retainedTurnNos = array_values(array_filter(
            $history->retainedTurnNos,
            static fn (int $t): bool => $t <= $afterTurnNo,
        ));
        $promptsByTurnNo = [];
        foreach ($history->promptsByTurnNo as $promptTurn => $text) {
            if (\in_array($promptTurn, $retainedTurnNos, true)) {
                $promptsByTurnNo[$promptTurn] = $text;
            }
        }

        $positionTurnNo = $history->positionTurnNo;
        if (0 === $afterTurnNo || [] === $retainedTurnNos) {
            $positionTurnNo = 0;
        } elseif (\in_array($afterTurnNo, $retainedTurnNos, true)) {
            $positionTurnNo = $afterTurnNo;
        } elseif ($positionTurnNo > $afterTurnNo) {
            $positionTurnNo = $retainedTurnNos[array_key_last($retainedTurnNos)];
        }

        return new HistoryDTO(
            retainedTurnNos: $retainedTurnNos,
            promptsByTurnNo: $promptsByTurnNo,
            positionTurnNo: $positionTurnNo,
        );
    }
}
