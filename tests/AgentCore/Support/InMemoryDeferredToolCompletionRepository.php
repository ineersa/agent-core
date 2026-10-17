<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Tests\Support;

use Ineersa\AgentCore\Contract\Tool\DeferredToolCompletionRepositoryInterface;
use Ineersa\AgentCore\Domain\Coordination\RecordToolInvocationLifecycleDTO;
use Ineersa\AgentCore\Domain\Tool\DeferredToolCompletionCorrelation;
use Ineersa\AgentCore\Domain\Tool\ToolInvocationEnvelopeFingerprint;
use Ineersa\AgentCore\Domain\Tool\ToolInvocationExecutionObservationEnum;

final class InMemoryDeferredToolCompletionRepository implements DeferredToolCompletionRepositoryInterface
{
    /** @var array<string, array{correlation: DeferredToolCompletionCorrelation, status: string}> */
    private array $byDeferredId = [];

    /** @var array<string, string> runId|toolCallId => deferredId */
    private array $byRunToolCall = [];

    public function registerPending(DeferredToolCompletionCorrelation $correlation): DeferredToolCompletionCorrelation
    {
        $key = $correlation->runId.'|'.$correlation->toolCallId;
        if (isset($this->byRunToolCall[$key])) {
            $deferredId = $this->byRunToolCall[$key];
            $existing = $this->byDeferredId[$deferredId]['correlation'];
            $existing->assertEnvelopeMatches($correlation);

            return $existing;
        }

        if (isset($this->byDeferredId[$correlation->deferredId])) {
            throw new \RuntimeException(\sprintf('Deferred id "%s" already registered.', $correlation->deferredId));
        }

        $computedFingerprint = ToolInvocationEnvelopeFingerprint::fromCorrelation($correlation);
        if (null !== $correlation->envelopeFingerprint && $correlation->envelopeFingerprint !== $computedFingerprint) {
            throw new \InvalidArgumentException('Envelope fingerprint must match the immutable execution envelope.');
        }

        $stored = new DeferredToolCompletionCorrelation(
            deferredId: $correlation->deferredId,
            runId: $correlation->runId,
            turnNo: $correlation->turnNo,
            stepId: $correlation->stepId,
            attempt: $correlation->attempt,
            idempotencyKey: $correlation->idempotencyKey,
            toolCallId: $correlation->toolCallId,
            toolName: $correlation->toolName,
            arguments: $correlation->arguments,
            orderIndex: $correlation->orderIndex,
            toolIdempotencyKey: $correlation->toolIdempotencyKey,
            mode: $correlation->mode,
            timeoutSeconds: $correlation->timeoutSeconds,
            maxParallelism: $correlation->maxParallelism,
            assistantMessage: $correlation->assistantMessage,
            argSchema: $correlation->argSchema,
            toolsRef: $correlation->toolsRef,
            invocationId: $correlation->invocationId,
            controllerRunId: $correlation->controllerRunId,
            executionOwnerKind: $correlation->executionOwnerKind,
            executionOwnerReference: $correlation->executionOwnerReference,
            originalExecutionMode: $correlation->originalExecutionMode,
            deadlineMetadata: $correlation->deadlineMetadata,
            originWakeGeneration: $correlation->originWakeGeneration,
            rootWakeGeneration: $correlation->rootWakeGeneration,
            envelopeFingerprint: $correlation->envelopeFingerprint ?? $computedFingerprint,
            callerDisposition: $correlation->callerDisposition,
            executionObservation: $correlation->executionObservation,
        );

        $this->byDeferredId[$correlation->deferredId] = [
            'correlation' => $stored,
            'status' => 'pending',
        ];
        $this->byRunToolCall[$key] = $correlation->deferredId;

        return $stored;
    }

    public function findByRunAndToolCall(string $runId, string $toolCallId): ?DeferredToolCompletionCorrelation
    {
        $deferredId = $this->byRunToolCall[$runId.'|'.$toolCallId] ?? null;
        if (null === $deferredId) {
            return null;
        }

        $row = $this->byDeferredId[$deferredId] ?? null;
        if (null === $row) {
            return null;
        }

        return $row['correlation'];
    }

    public function findByDeferredId(string $deferredId): ?DeferredToolCompletionCorrelation
    {
        return $this->byDeferredId[$deferredId]['correlation'] ?? null;
    }

    public function status(string $deferredId): ?string
    {
        return $this->byDeferredId[$deferredId]['status'] ?? null;
    }

    /** Test-only mutation helper for injecting a conflicting durable correlation envelope. */
    public function replaceForTest(DeferredToolCompletionCorrelation $correlation): void
    {
        $key = $correlation->runId.'|'.$correlation->toolCallId;
        $existingDeferredId = $this->byRunToolCall[$key] ?? null;
        if (null === $existingDeferredId || !isset($this->byDeferredId[$existingDeferredId])) {
            throw new \RuntimeException(\sprintf('Deferred tool correlation missing for run "%s" tool call "%s".', $correlation->runId, $correlation->toolCallId));
        }

        if ($existingDeferredId !== $correlation->deferredId) {
            throw new \RuntimeException('replaceForTest requires the same deferred id.');
        }

        $computedFingerprint = ToolInvocationEnvelopeFingerprint::fromCorrelation($correlation);
        $stored = new DeferredToolCompletionCorrelation(
            deferredId: $correlation->deferredId,
            runId: $correlation->runId,
            turnNo: $correlation->turnNo,
            stepId: $correlation->stepId,
            attempt: $correlation->attempt,
            idempotencyKey: $correlation->idempotencyKey,
            toolCallId: $correlation->toolCallId,
            toolName: $correlation->toolName,
            arguments: $correlation->arguments,
            orderIndex: $correlation->orderIndex,
            toolIdempotencyKey: $correlation->toolIdempotencyKey,
            mode: $correlation->mode,
            timeoutSeconds: $correlation->timeoutSeconds,
            maxParallelism: $correlation->maxParallelism,
            assistantMessage: $correlation->assistantMessage,
            argSchema: $correlation->argSchema,
            toolsRef: $correlation->toolsRef,
            invocationId: $correlation->invocationId,
            controllerRunId: $correlation->controllerRunId,
            executionOwnerKind: $correlation->executionOwnerKind,
            executionOwnerReference: $correlation->executionOwnerReference,
            originalExecutionMode: $correlation->originalExecutionMode,
            deadlineMetadata: $correlation->deadlineMetadata,
            originWakeGeneration: $correlation->originWakeGeneration,
            rootWakeGeneration: $correlation->rootWakeGeneration,
            envelopeFingerprint: $correlation->envelopeFingerprint ?? $computedFingerprint,
            callerDisposition: $correlation->callerDisposition,
            executionObservation: $correlation->executionObservation,
        );

        $this->byDeferredId[$correlation->deferredId]['correlation'] = $stored;
    }

    public function applyLifecycleProjection(RecordToolInvocationLifecycleDTO $action): void
    {
        $expected = $action->expectedCorrelation;
        $key = $expected->runId.'|'.$expected->toolCallId;
        $deferredId = $this->byRunToolCall[$key] ?? null;
        if (null === $deferredId || !isset($this->byDeferredId[$deferredId])) {
            throw new \RuntimeException(\sprintf('Deferred tool lifecycle projection missing for run "%s" tool call "%s".', $expected->runId, $expected->toolCallId));
        }

        $current = $this->byDeferredId[$deferredId]['correlation'];
        $current->assertAcceptedImmutableBindingMatches($expected);
        if ($current->callerDisposition === $action->callerDisposition
            && $current->executionObservation === $action->executionObservation) {
            if (null !== $action->acceptedCompletionId) {
                if ($current->acceptedCompletionId === $action->acceptedCompletionId
                    && $current->acceptedResultFingerprint === $action->acceptedResultFingerprint
                    && 'completed' === $this->byDeferredId[$deferredId]['status']) {
                    return;
                }
                if (null !== $current->acceptedCompletionId
                    && ($current->acceptedCompletionId !== $action->acceptedCompletionId
                        || $current->acceptedResultFingerprint !== $action->acceptedResultFingerprint)) {
                    throw new \RuntimeException(\sprintf('Conflicting accepted completion for run "%s" tool call "%s".', $expected->runId, $expected->toolCallId));
                }
            } else {
                return;
            }
        }

        $next = $current->withLifecycle(
            $action->callerDisposition,
            $action->executionObservation,
            $action->acceptedCompletionId,
            $action->acceptedResultFingerprint,
        );
        $this->byDeferredId[$deferredId]['correlation'] = $next;
        if (null !== $next->acceptedCompletionId
            && (ToolInvocationExecutionObservationEnum::Terminal === $next->executionObservation
                || ToolInvocationExecutionObservationEnum::Unknown === $next->executionObservation)) {
            $this->byDeferredId[$deferredId]['status'] = 'completed';
        }
    }
}
