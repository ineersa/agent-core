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
use Ineersa\CodingAgent\Application\Message\ConsumeSubagentProgressDTO;
use Ineersa\CodingAgent\Entity\DeferredSubagentBatchRepository;

final readonly class CommitSubagentProgressHandler implements RunMessageHandler
{
    public function __construct(
        private DeferredSubagentBatchRepository $batchRepository,
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

        if ($forced && $batch->interruptionKind?->value !== $message->interruptionKind) {
            throw new \RuntimeException('Subagent progress interruption identity mismatch.');
        }
        // Cancellation may already have cleared the parent's tool map. Its
        // forced snapshot still belongs to the interrupted batch until a new turn.
        if ($state->turnNo !== $message->turnNo()
            || (!$forced && false !== ($state->pendingToolCalls[$message->toolCallId] ?? null))) {
            return new HandlerResult(postCommitActions: [new ConsumeSubagentProgressDTO($message->lifecycleId, $message->revision, $forced, true, new \DateTimeImmutable())]);
        }
        if (($forced && null !== $batch->interruptionProgressEnqueuedAt)
            || (!$forced && ($message->revision <= $batch->deliveredProgressRevision
                || $message->revision < $batch->aggregateProgressRevision || null !== $batch->interruptionKind))) {
            return new HandlerResult(postCommitActions: [new DeliverDeferredSubagentBatchLifecycleMessage($message->lifecycleId)]);
        }

        $event = new RunEvent($message->runId(), 0, $message->turnNo(), RunEventTypeEnum::ToolExecutionUpdate->value, [
            'tool_call_id' => $message->toolCallId, 'tool_name' => 'subagent', 'delta' => '',
            'subagent_progress' => $message->progress, 'order_index' => $message->orderIndex,
        ]);

        // The coordination handler re-reads the projection after commit; producer versions
        // need not remain current while the command waits for consumption.
        return new HandlerResult(nextState: $state, events: [$event], postCommitActions: [new ConsumeSubagentProgressDTO($message->lifecycleId, $message->revision, $forced, false, new \DateTimeImmutable())]);
    }
}
