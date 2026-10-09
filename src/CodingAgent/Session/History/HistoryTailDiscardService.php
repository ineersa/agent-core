<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Session\History;

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
use Ineersa\CodingAgent\Session\SessionRunEventStore;
use Psr\Log\LoggerInterface;

/**
 * Prepares history_tail_discarded when a context-mutating message would diverge
 * while active turns exist after the current selected tip.
 *
 * Shared choke point used by RunMessageProcessor before handlers run.
 * An actual discard also clears Astra reasoning_baseline so transitions
 * anchored on discarded forward history cannot suppress the selected effort.
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
        private HatfieldSessionStore $sessionMetadataStore,
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
        // Drop transitions keyed to the discarded forward tail so the next
        // request re-establishes the still-selected effort as baseline.
        $this->sessionMetadataStore->resetReasoningBaseline($runId);

        $this->logger->info('history_tail_discarded.appended', [
            'run_id' => $runId,
            'component' => 'history',
            'event_type' => 'history_tail_discarded',
        ]);
    }
}
