<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Session\History;

use Ineersa\AgentCore\Contract\ActiveRunContextInterface;
use Ineersa\AgentCore\Contract\CommandStoreInterface;
use Ineersa\AgentCore\Contract\History\HistoryTailDiscardInterface;
use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Domain\Event\RunEventTypeEnum;
use Ineersa\AgentCore\Domain\Message\AbstractAgentBusMessage;
use Ineersa\AgentCore\Domain\Message\AdvanceRun;
use Ineersa\AgentCore\Domain\Message\ApplyCommand;
use Ineersa\AgentCore\Domain\Message\ApplyShellCommand;
use Ineersa\AgentCore\Domain\Message\CompactRun;
use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\CodingAgent\Application\BackgroundCompletion\BackgroundCompletionDispositionEnum;
use Ineersa\CodingAgent\Application\BackgroundCompletion\DTO\BackgroundCompletionProvenanceDTO;
use Ineersa\CodingAgent\Application\Message\DTO\DeliverBackgroundCompletionDTO;
use Ineersa\CodingAgent\Entity\BackgroundCompletionReceiptRepository;
use Ineersa\CodingAgent\Session\SessionRunEventStore;
use Psr\Log\LoggerInterface;

/**
 * Prepares history_tail_discarded when a context-mutating message would diverge
 * while active turns exist after the current selected tip.
 *
 * Shared choke point used by RunMessageProcessor before handlers run.
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
        private SessionRunEventStore $eventStore,
        private LoggerInterface $logger,
        private ActiveRunContextInterface $contexts,
        private BackgroundCompletionReceiptRepository $receipts,
        private CommandStoreInterface $commands,
    ) {
    }

    public function isContextMutatingMessage(AbstractAgentBusMessage $message): bool
    {
        if ($message instanceof DeliverBackgroundCompletionDTO) {
            if (null !== $message->deferredId) {
                return false;
            }
            // The processor has recovered pending transitions and holds the
            // owner lock before asking this policy. Only actual admission has
            // append-message history semantics; retries and transfers do not.
            $receipt = $this->receipts->receipt($message->identity);
            if (null !== $receipt) {
                return BackgroundCompletionDispositionEnum::Forwarding === $receipt->disposition
                    && $receipt->destinationRunId === $message->runId();
            }
            $state = $this->contexts->requireLoaded($message->runId());
            if (null !== $message->sourceRunId && $message->sourceRunId !== $state->runId) {
                return false;
            }

            return null === $state->parentRunId || (!$state->status->isTerminal() && RunStatus::Cancelling !== $state->status);
        }
        if ($message instanceof AdvanceRun) {
            $pending = $this->commands->pending($message->runId());
            if ([] !== $pending && array_all($pending, static fn ($command): bool => ($command->payload['provenance'] ?? null) instanceof BackgroundCompletionProvenanceDTO)) {
                return false;
            }
        }
        if ($message instanceof AdvanceRun || $message instanceof ApplyShellCommand || $message instanceof CompactRun) {
            return true;
        }

        if ($message instanceof ApplyCommand) {
            if (($message->payload['provenance'] ?? null) instanceof BackgroundCompletionProvenanceDTO) {
                return false;
            }

            return \in_array($message->kind, self::MUTATING_COMMAND_KINDS, true);
        }

        return false;
    }

    public function prepareForwardTailDiscard(string $runId, RunState $state): ?RunEvent
    {
        $tip = $state->turnNo;
        if (!$this->eventStore->hasForwardTail($runId, $tip)) {
            return null;
        }

        return new RunEvent(
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
    }

    public function afterDiscardCommitted(string $runId): void
    {
        $this->logger->info('history_tail_discarded.appended', [
            'run_id' => $runId,
            'component' => 'history',
            'event_type' => 'history_tail_discarded',
        ]);
    }
}
