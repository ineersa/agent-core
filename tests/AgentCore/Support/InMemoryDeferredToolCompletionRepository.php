<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Tests\Support;

use Ineersa\AgentCore\Contract\Tool\DeferredToolCompletionRepositoryInterface;
use Ineersa\AgentCore\Domain\Coordination\RecordToolInvocationLifecycleDTO;
use Ineersa\AgentCore\Domain\Tool\DeferredToolCompletionCorrelation;
use Ineersa\AgentCore\Domain\Tool\ToolInvocationEnvelopeFingerprint;

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
            envelopeFingerprint: $correlation->envelopeFingerprint ?? ToolInvocationEnvelopeFingerprint::fromCorrelation($correlation),
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

    public function markCompleted(string $deferredId): void
    {
        if (isset($this->byDeferredId[$deferredId])) {
            $this->byDeferredId[$deferredId]['status'] = 'completed';
        }
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
        $current->assertEnvelopeMatches($expected);
        if ($current->callerDisposition === $action->callerDisposition
            && $current->executionObservation === $action->executionObservation) {
            return;
        }

        $this->byDeferredId[$deferredId]['correlation'] = $current->withLifecycle(
            $action->callerDisposition,
            $action->executionObservation,
        );
    }
}
