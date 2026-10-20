<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Session;

use Ineersa\AgentCore\Contract\ActiveRunContextInterface;
use Ineersa\AgentCore\Contract\Replay\RunStateRebuilderInterface;
use Ineersa\AgentCore\Contract\Tool\ToolBatchStoreInterface;
use Ineersa\AgentCore\Domain\Message\ExecuteShellToolCall;
use Ineersa\AgentCore\Domain\Message\ExecuteToolCall;
use Ineersa\AgentCore\Domain\Message\ToolCallResult;
use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\AgentCore\Domain\Tool\DTO\ToolPayloadReferenceDTO;
use Ineersa\CodingAgent\Session\Exception\ToolPayloadException;

/** Admission reads existing scheduling/canonical state, never a second execution ledger. */
final readonly class ToolPayloadAdmission
{
    public function __construct(
        private ToolBatchStoreInterface $batches,
        private ActiveRunContextInterface $registry,
        private RunStateRebuilderInterface $replay,
    ) {
    }

    /** Do not recreate payload files for work already settled by the owner. */
    public function canPublish(ExecuteToolCall|ExecuteShellToolCall|ToolCallResult $message): bool
    {
        $call = $this->batches->loadCallReference($message->runId(), $message->turnNo(), $message->stepId(), $message->toolCallId);
        if (null !== $call) {
            if ($call->attempt !== $message->attempt() || $call->idempotencyKey !== $message->idempotencyKey()) {
                return false;
            }
            if ($message instanceof ToolCallResult) {
                return true;
            }
            $schedule = $this->batches->loadSchedule($message->runId(), $message->turnNo(), $message->stepId());

            return null !== $schedule && isset($schedule->inFlight[$message->toolCallId]) && !isset($schedule->completedIds[$message->toolCallId]);
        }
        // Loading an existing schedule detects a missing member row rather
        // than silently treating damaged active metadata as a stale delivery.
        $this->batches->loadSchedule($message->runId(), $message->turnNo(), $message->stepId());
        $state = $this->replay->rebuildIfStale(RunState::queued($message->runId()), $message->runId())->rebuiltState;
        if (null !== $state && $state->turnNo === $message->turnNo() && $state->activeStepId === $message->stepId() && \array_key_exists($message->toolCallId, $state->pendingToolCalls)) {
            throw new ToolPayloadException('Active tool invocation is missing its batch data reference.');
        }

        return !$message instanceof ExecuteToolCall && null !== $state && isset($state->pendingShellToolCalls[$message->toolCallId]);
    }

    /** Called under the existing run lock, before opening a possibly cleaned file. */
    public function accepts(ToolPayloadReferenceDTO $reference, bool $owner): bool
    {
        $state = $owner ? $this->registry->requireLoaded($reference->runId) : null;
        if (null !== $state && 'result' === $reference->kind && !isset($state->pendingShellToolCalls[$reference->toolCallId])) {
            if (!\array_key_exists($reference->toolCallId, $state->pendingToolCalls)) {
                return false;
            }
        }
        $call = $this->batches->loadCallReference($reference->runId, $reference->turnNo, $reference->stepId, $reference->toolCallId);
        if (null !== $call) {
            if ($call->attempt !== $reference->attempt || $call->idempotencyKey !== $reference->idempotencyKey || $call->orderIndex !== $reference->orderIndex) {
                return false;
            }
            if ('call' !== $reference->kind) {
                return 'result' === $reference->kind;
            }
            $schedule = $this->batches->loadSchedule($reference->runId, $reference->turnNo, $reference->stepId);

            return $call->sha256 === $reference->sha256 && null !== $schedule && isset($schedule->inFlight[$reference->toolCallId]) && !isset($schedule->completedIds[$reference->toolCallId]);
        }
        $this->batches->loadSchedule($reference->runId, $reference->turnNo, $reference->stepId);
        // Direct shell work has no batch. Reuse canonical reconstruction only
        // for that path or damaged/missing metadata; normal admission is scalar.
        $state ??= $this->replay->rebuildIfStale(RunState::queued($reference->runId), $reference->runId)->rebuiltState;
        if (null !== $state && $state->turnNo === $reference->turnNo && $state->activeStepId === $reference->stepId && \array_key_exists($reference->toolCallId, $state->pendingToolCalls)) {
            throw new ToolPayloadException('Active tool invocation is missing its batch data reference.');
        }

        return 'call' !== $reference->kind && null !== $state && isset($state->pendingShellToolCalls[$reference->toolCallId]);
    }

    /** Deferred destinations can retire while their correlation still retains a batch. */
    public function acceptsDeferred(ToolPayloadReferenceDTO $reference, bool $owner): bool
    {
        $state = $owner ? $this->registry->requireLoaded($reference->runId)
            : $this->replay->rebuildIfStale(RunState::queued($reference->runId), $reference->runId)->rebuiltState;
        if (null === $state) {
            throw new ToolPayloadException('Deferred completion requires canonical owner state.');
        }
        if ($state->turnNo !== $reference->turnNo || $state->activeStepId !== $reference->stepId || !\array_key_exists($reference->toolCallId, $state->pendingToolCalls)) {
            return false;
        }
        $call = $this->batches->loadCallReference($reference->runId, $reference->turnNo, $reference->stepId, $reference->toolCallId);
        if (null === $call) {
            throw new ToolPayloadException('Active deferred invocation is missing its batch data reference.');
        }

        return [$call->attempt, $call->idempotencyKey, $call->orderIndex] === [$reference->attempt, $reference->idempotencyKey, $reference->orderIndex];
    }

    /** Propagate an unreadable active invocation without inventing its arguments. */
    public function failedInvocation(ToolPayloadReferenceDTO $reference): ToolCallResult
    {
        $result = ['tool_name' => $reference->toolName];
        if ('shell' === $reference->kind) {
            $state = $this->replay->rebuildIfStale(RunState::queued($reference->runId), $reference->runId)->rebuiltState;
            // An attached shell must not complete the model's current operation.
            $result['standalone'] = $state?->currentOperation?->matches($reference->turnNo, $reference->stepId, $reference->attempt, $reference->idempotencyKey) ?? false;
        }

        return (new ToolCallResult(
            $reference->runId, $reference->turnNo, $reference->stepId,
            $reference->attempt, $reference->idempotencyKey,
            $reference->toolCallId, $reference->orderIndex, $result,
        ))->withRepresentationFailure('Tool invocation data is missing or invalid.');
    }

    /** Match the ordinary run processor's terminal-child release on owner no-ops. */
    public function releaseTerminalChild(string $runId): void
    {
        $state = $this->registry->requireLoaded($runId);
        if (null !== $state->parentRunId && $state->status->isTerminal()) {
            $this->registry->release($runId);
        }
    }
}
