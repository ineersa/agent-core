<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Session;

use Ineersa\AgentCore\Contract\ActiveRunContextInterface;
use Ineersa\AgentCore\Contract\EventStoreInterface;
use Ineersa\AgentCore\Contract\Replay\RunStateRebuilderInterface;
use Ineersa\AgentCore\Contract\RunContextNotLoadedException;
use Ineersa\AgentCore\Contract\Tool\DeferredToolCompletionRepositoryInterface;
use Ineersa\AgentCore\Contract\Tool\ToolBatchStoreInterface;
use Ineersa\AgentCore\Contract\Tool\ToolPayloadStoreInterface;
use Ineersa\AgentCore\Domain\Message\ExecuteShellToolCall;
use Ineersa\AgentCore\Domain\Message\ExecuteToolCall;
use Ineersa\AgentCore\Domain\Message\ToolCallResult;
use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\AgentCore\Domain\Tool\DeferredToolInvocationFence;
use Ineersa\AgentCore\Domain\Tool\DTO\ToolPayloadReferenceDTO;
use Ineersa\CodingAgent\Session\DTO\PendingToolInvocationDTO;
use Ineersa\CodingAgent\Session\Exception\ToolPayloadException;
use Psr\Log\LoggerInterface;

/** Admission reads existing scheduling/canonical state, never a second execution ledger. */
final readonly class ToolPayloadAdmission
{
    public function __construct(
        private ToolBatchStoreInterface $batches,
        private ActiveRunContextInterface $registry,
        private RunStateRebuilderInterface $replay,
        private EventStoreInterface $events,
        private LoggerInterface $logger,
        private CanonicalToolInvocationReader $invocations,
        private DeferredToolCompletionRepositoryInterface $deferred,
        private ToolPayloadStoreInterface $payloads,
        private DeferredToolInvocationFence $fence,
    ) {
    }

    /** Do not recreate payload files for work already settled by the owner. */
    public function canPublish(ExecuteToolCall|ExecuteShellToolCall|ToolCallResult $message): bool
    {
        $call = $this->batches->loadCallReference($message->runId(), $message->turnNo(), $message->stepId(), $message->toolCallId);
        if (null !== $call) {
            if (!$this->matchesAttemptAndKey($call, $message->attempt(), $message->idempotencyKey())) {
                return false;
            }
            if ($message instanceof ToolCallResult) {
                return true;
            }

            return $this->isInFlight($call);
        }
        // Loading an existing schedule detects a missing member row rather
        // than silently treating damaged active metadata as a stale delivery.
        $this->batches->loadSchedule($message->runId(), $message->turnNo(), $message->stepId());
        $state = $this->loadedState($message->runId());
        if (null !== $state && $this->hasPendingCall($state, $message->turnNo(), $message->stepId(), $message->toolCallId)) {
            throw new ToolPayloadException('Active tool invocation is missing its batch data reference.');
        }

        if (null !== $state) {
            return !$message instanceof ExecuteToolCall && isset($state->pendingShellToolCalls[$message->toolCallId]);
        }

        $shell = $this->pendingShell($message->runId(), $message->toolCallId, $message->turnNo(), $message->stepId(), $message->attempt(), $message->idempotencyKey());

        return !$message instanceof ExecuteToolCall && null !== $shell;
    }

    /** Called under the existing run lock, before opening a possibly cleaned file. */
    public function accepts(ToolPayloadReferenceDTO $reference, bool $owner): bool
    {
        if ('result' === $reference->kind && $this->deferred->requiresContinuation($reference->runId, $reference->toolCallId)) {
            return false;
        }
        $state = $owner ? $this->registry->requireLoaded($reference->runId) : null;
        if (null !== $state && 'result' === $reference->kind && !isset($state->pendingShellToolCalls[$reference->toolCallId])) {
            if (!\array_key_exists($reference->toolCallId, $state->pendingToolCalls)) {
                return false;
            }
        }
        $call = $this->batches->loadCallReference($reference->runId, $reference->turnNo, $reference->stepId, $reference->toolCallId);
        if (null !== $call) {
            if (!$this->matchesAttemptAndKey($call, $reference->attempt, $reference->idempotencyKey) || $call->orderIndex !== $reference->orderIndex) {
                return false;
            }
            if ('call' !== $reference->kind) {
                return 'result' === $reference->kind;
            }
            // Invocation bodies must match the retained digest. Result bodies
            // differ from their invocation and need no scheduling admission.
            $inFlight = $this->isInFlight($call);

            return $call->sha256 === $reference->sha256 && $inFlight;
        }
        $this->batches->loadSchedule($reference->runId, $reference->turnNo, $reference->stepId);
        // Direct shells have no SQL batch. Unloaded processes read canonical
        // scalar locations, never reconstruct the session's messages or results.
        $state ??= $this->loadedState($reference->runId);
        if (null !== $state && $this->hasPendingCall($state, $reference->turnNo, $reference->stepId, $reference->toolCallId)) {
            throw new ToolPayloadException('Active tool invocation is missing its batch data reference.');
        }

        if (null !== $state) {
            return 'call' !== $reference->kind && isset($state->pendingShellToolCalls[$reference->toolCallId]);
        }

        return null !== $this->pendingShell($reference->runId, $reference->toolCallId, $reference->turnNo, $reference->stepId, $reference->attempt, $reference->idempotencyKey) && 'call' !== $reference->kind;
    }

    /** Deferred destinations may advance after acknowledgement; retained invocation is authoritative. */
    public function acceptsDeferred(ToolPayloadReferenceDTO $reference, bool $owner, ?string $deferredId = null): bool
    {
        if (null !== $deferredId) {
            $invocation = $this->deferred->findInvocationReferenceByDeferredId($deferredId);
            if (null === $invocation) {
                return false;
            }
            // Unacknowledged work still retires when its destination is gone.
            // Only an acknowledged retained invocation may outlive parent advancement.
            if (null !== $this->deferred->acknowledgedAt($deferredId)) {
                $call = $this->payloads->read($invocation);
                if (!$call instanceof ExecuteToolCall) {
                    throw new ToolPayloadException('Deferred completion retained invocation is invalid.');
                }
                if ([$call->runId(), $call->turnNo(), $call->stepId(), $call->attempt(), $call->idempotencyKey(), $call->toolCallId, $call->orderIndex, $call->toolName]
                    !== [$reference->runId, $reference->turnNo, $reference->stepId, $reference->attempt, $reference->idempotencyKey, $reference->toolCallId, $reference->orderIndex, $reference->toolName]) {
                    return false;
                }
                $live = $this->batches->loadCall($reference->runId, $reference->turnNo, $reference->stepId, $reference->toolCallId);
                if (null !== $live && !$this->fence->sameInvocation($call, $live)) {
                    return false;
                }
                if ($owner) {
                    $this->registry->requireLoaded($reference->runId);
                }

                return true;
            }
        }
        $state = $owner ? $this->registry->requireLoaded($reference->runId)
            : $this->loadedState($reference->runId);
        if (null === $state) {
            $pending = $this->invocations->find($reference->runId, $reference->toolCallId);
            if (null === $pending && null === $this->events->latestSequenceFor($reference->runId)) {
                throw new ToolPayloadException('Deferred completion requires canonical owner state.');
            }
            // Approval resumes update the batch attempt without another start event.
            // Canonical history owns membership; the batch below owns invocation identity.
            if (null === $pending || $pending->shell || [$pending->turnNo, $pending->stepId] !== [$reference->turnNo, $reference->stepId]) {
                return false;
            }
        } elseif (!$this->hasPendingCall($state, $reference->turnNo, $reference->stepId, $reference->toolCallId)) {
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
            $state = $this->loadedState($reference->runId);
            // An attached shell must not complete the model's current operation.
            // Execution has its own key; the shell ID derives from the command key.
            if (null !== $state) {
                $result['standalone'] = null !== $state->currentOperation
                    && $reference->toolCallId === 'sh_'.hash('sha256', $state->currentOperation->idempotencyKey);
            } else {
                $pending = $this->pendingShell($reference->runId, $reference->toolCallId, $reference->turnNo, $reference->stepId, $reference->attempt, $reference->idempotencyKey);
                if (null === $pending) {
                    throw new ToolPayloadException('Failed invocation no longer has a pending shell identity.');
                }
                $result['standalone'] = $pending->standalone;
            }
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

    private function matchesAttemptAndKey(ToolPayloadReferenceDTO $call, int $attempt, string $key): bool
    {
        return $call->attempt === $attempt && $call->idempotencyKey === $key;
    }

    private function isInFlight(ToolPayloadReferenceDTO $call): bool
    {
        $schedule = $this->batches->loadSchedule($call->runId, $call->turnNo, $call->stepId);

        return null !== $schedule && isset($schedule->inFlight[$call->toolCallId]) && !isset($schedule->completedIds[$call->toolCallId]);
    }

    private function hasPendingCall(RunState $state, int $turnNo, string $stepId, string $toolCallId): bool
    {
        return $state->turnNo === $turnNo && $state->activeStepId === $stepId && \array_key_exists($toolCallId, $state->pendingToolCalls);
    }

    private function pendingShell(string $runId, string $toolCallId, int $turnNo, string $stepId, int $attempt, string $key): ?PendingToolInvocationDTO
    {
        $pending = $this->invocations->find($runId, $toolCallId);
        if (null === $pending || [$pending->turnNo, $pending->stepId, $pending->attempt] !== [$turnNo, $stepId, $attempt]) {
            return null;
        }
        if (!$pending->shell) {
            throw new ToolPayloadException('Active tool invocation is missing its batch data reference.');
        }

        return hash('sha256', $runId.'|'.$toolCallId) === $key ? $pending : null;
    }

    private function loadedState(string $runId): ?RunState
    {
        try {
            $state = $this->registry->requireLoaded($runId);
        } catch (RunContextNotLoadedException $exception) {
            // Execution processes use the canonical index instead of an owner graph.
            $this->logger->debug('tool_payload.unloaded_admission', [
                'run_id' => $runId, 'session_id' => $runId, 'component' => 'tool_payload',
                'event_type' => 'tool_payload.unloaded_admission', 'exception_class' => $exception::class,
            ]);

            return null;
        }
        $sequence = $this->events->latestSequenceFor($runId);
        if (null === $sequence || $state->lastSeq > $sequence) {
            throw new ToolPayloadException('Loaded admission state has no matching canonical history.');
        }
        if ($state->lastSeq === $sequence) {
            return $state;
        }

        return $this->replay->rebuildIfStale($state, $runId)->rebuiltState
            ?? throw new ToolPayloadException('Stale admission state could not be recovered.');
    }
}
