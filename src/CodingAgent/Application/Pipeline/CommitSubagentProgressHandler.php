<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Application\Pipeline;

use Ineersa\AgentCore\Application\Pipeline\HandlerResult;
use Ineersa\AgentCore\Application\Pipeline\RunMessageHandler;
use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Domain\Event\RunEventTypeEnum;
use Ineersa\AgentCore\Domain\Message\CommitSubagentProgress;
use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\Lifecycle\DeliverDeferredSubagentBatchLifecycleMessage;
use Ineersa\CodingAgent\Entity\DeferredSubagentBatchRepository;
use Symfony\Component\Messenger\MessageBusInterface;

final readonly class CommitSubagentProgressHandler implements RunMessageHandler
{
    public function __construct(
        private DeferredSubagentBatchRepository $batchRepository,
        private MessageBusInterface $commandBus,
    ) {
    }

    public function supports(object $message): bool
    {
        return $message instanceof CommitSubagentProgress;
    }

    public function handle(object $message, RunState $state): HandlerResult
    {
        \assert($message instanceof CommitSubagentProgress);
        $batch = $this->batchRepository->findByLifecycleId($message->lifecycleId);
        if (null === $batch) {
            throw new \RuntimeException('Unknown subagent progress lifecycle.');
        }
        if ($batch->parentRunId !== $message->runId() || $batch->parentTurnNo !== $message->turnNo()
            || $batch->parentToolCallId !== $message->toolCallId || $batch->parentOrderIndex !== $message->orderIndex) {
            throw new \RuntimeException('Subagent progress invocation identity mismatch.');
        }
        if ($message->revision > $batch->aggregateProgressRevision || $message->revision < 0) {
            throw new \RuntimeException('Invalid subagent progress revision.');
        }
        $forced = null !== $message->interruptionKind;
        if (null !== $batch->terminalCompletionEnqueuedAt) {
            return new HandlerResult();
        }

        $redeliver = function () use ($message): void {
            $this->commandBus->dispatch(new DeliverDeferredSubagentBatchLifecycleMessage($message->lifecycleId));
        };
        if ($forced && $batch->interruptionKind?->value !== $message->interruptionKind) {
            throw new \RuntimeException('Subagent progress interruption identity mismatch.');
        }
        $consume = function (bool $discarded) use ($message, $forced, $redeliver): void {
            $current = $this->batchRepository->findByLifecycleId($message->lifecycleId)
                ?? throw new \RuntimeException('Subagent progress lifecycle disappeared during consumption.');
            if ($forced) {
                $this->batchRepository->markInterruptionProgressEnqueued($current->lifecycleId, new \DateTimeImmutable(), $current->projectionVersion);
            } else {
                // A superseded invocation no longer has a progress destination.
                // Retire its current obligation, including newer observations,
                // without claiming that those snapshots were canonically appended.
                $revision = $discarded ? $current->aggregateProgressRevision : $message->revision;
                $this->batchRepository->markDeliveredProgressRevision($current->lifecycleId, $revision, $current->projectionVersion);
            }
            $redeliver();
        };
        // Cancellation may already have cleared the parent's tool map. Its
        // forced snapshot still belongs to the interrupted batch until a new turn.
        if ($state->turnNo !== $message->turnNo()
            || (!$forced && false !== ($state->pendingToolCalls[$message->toolCallId] ?? null))) {
            return new HandlerResult(postCommit: [static fn () => $consume(true)]);
        }
        if (($forced && null !== $batch->interruptionProgressEnqueuedAt)
            || (!$forced && ($message->revision <= $batch->deliveredProgressRevision
                || $message->revision < $batch->aggregateProgressRevision || null !== $batch->interruptionKind))) {
            return new HandlerResult(postCommit: [$redeliver]);
        }

        $event = new RunEvent($message->runId(), 0, $message->turnNo(), RunEventTypeEnum::ToolExecutionUpdate->value, [
            'tool_call_id' => $message->toolCallId, 'tool_name' => 'subagent', 'delta' => '',
            'subagent_progress' => $message->progress, 'order_index' => $message->orderIndex,
        ]);

        // The callback re-reads the projection after commit; producer versions
        // need not remain current while the command waits for consumption.
        return new HandlerResult(nextState: $state, events: [$event], postCommit: [static fn () => $consume(false)]);
    }
}
