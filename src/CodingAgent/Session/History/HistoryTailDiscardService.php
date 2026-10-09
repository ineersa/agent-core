<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Session\History;

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
        private EventStoreInterface $eventStore,
        private HistoryProjector $projector,
        private LoggerInterface $logger,
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

    public function prepareForwardTailDiscard(string $runId, RunState $state): ?RunEvent
    {
        // ponytail: full event-log rebuild O(n) per mutate-behind-tip; cache tip/active if discard checks become hot.
        $events = $this->eventStore->allFor($runId);
        if ([] === $events) {
            return null;
        }

        $history = $this->projector->build($events);
        $active = $history->retainedTurnNos;
        if ([] === $active) {
            return null;
        }

        // Invalid / non-retained current state must not fabricate a discard.
        $tip = $state->turnNo;
        if (0 !== $tip && !\in_array($tip, $active, true)) {
            return null;
        }

        $orderedTip = $active[array_key_last($active)];
        if ($tip >= $orderedTip) {
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
