<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Domain\Tool;

/**
 * Durable snapshot of the original ExecuteToolCall envelope for later completion.
 *
 * Authoritative invocation identity is (runId, turnNo, stepId, toolCallId).
 * invocationId is a stable alias only; (runId, toolCallId) uniqueness remains the store key.
 */
final readonly class DeferredToolCompletionCorrelation
{
    /**
     * @param array<string, mixed>      $arguments
     * @param array<string, mixed>|null $assistantMessage
     * @param array<string, mixed>|null $argSchema
     * @param array<string, mixed>|null $deadlineMetadata
     */
    public function __construct(
        public string $deferredId,
        public string $runId,
        public int $turnNo,
        public string $stepId,
        public int $attempt,
        public string $idempotencyKey,
        public string $toolCallId,
        public string $toolName,
        public array $arguments,
        public int $orderIndex,
        public ?string $toolIdempotencyKey = null,
        public ?string $mode = null,
        public ?int $timeoutSeconds = null,
        public ?int $maxParallelism = null,
        public ?array $assistantMessage = null,
        public ?array $argSchema = null,
        public ?string $toolsRef = null,
        public ?string $invocationId = null,
        public ?string $controllerRunId = null,
        public ?string $executionOwnerKind = null,
        public ?ToolInvocationOwnerReference $executionOwnerReference = null,
        public ?string $originalExecutionMode = null,
        public ?array $deadlineMetadata = null,
        public ?int $originWakeGeneration = null,
        public ?int $rootWakeGeneration = null,
        public ?string $envelopeFingerprint = null,
        public ToolInvocationCallerDispositionEnum $callerDisposition = ToolInvocationCallerDispositionEnum::ForegroundPending,
        public ToolInvocationExecutionObservationEnum $executionObservation = ToolInvocationExecutionObservationEnum::Active,
    ) {
        if (null !== $this->executionOwnerKind && '' === $this->executionOwnerKind) {
            throw new \InvalidArgumentException('Execution owner kind cannot be empty when present.');
        }
        if (null !== $this->executionOwnerReference && null !== $this->executionOwnerKind && $this->executionOwnerReference->kind !== $this->executionOwnerKind) {
            throw new \InvalidArgumentException('Execution owner kind must match the owner reference kind.');
        }
        if (null !== $this->originWakeGeneration && $this->originWakeGeneration < 0) {
            throw new \InvalidArgumentException('Origin wake generation cannot be negative.');
        }
        if (null !== $this->rootWakeGeneration && $this->rootWakeGeneration < 0) {
            throw new \InvalidArgumentException('Root wake generation cannot be negative.');
        }
    }

    public function matchesInvocationTuple(string $runId, int $turnNo, string $stepId, string $toolCallId): bool
    {
        return $this->runId === $runId
            && $this->turnNo === $turnNo
            && $this->stepId === $stepId
            && $this->toolCallId === $toolCallId;
    }

    public function assertEnvelopeMatches(self $candidate): void
    {
        if (!$this->matchesInvocationTuple($candidate->runId, $candidate->turnNo, $candidate->stepId, $candidate->toolCallId)) {
            throw new \RuntimeException(\sprintf('Deferred invocation tuple mismatch for tool call "%s": expected %s/%d/%s, got %s/%d/%s.', $this->toolCallId, $this->runId, $this->turnNo, $this->stepId, $candidate->runId, $candidate->turnNo, $candidate->stepId));
        }

        $expectedFingerprint = $this->envelopeFingerprint ?? ToolInvocationEnvelopeFingerprint::fromCorrelation($this);
        $actualFingerprint = $candidate->envelopeFingerprint ?? ToolInvocationEnvelopeFingerprint::fromCorrelation($candidate);
        if ($expectedFingerprint !== $actualFingerprint) {
            throw new \RuntimeException(\sprintf('Deferred execution envelope mismatch for run "%s" tool call "%s".', $this->runId, $this->toolCallId));
        }
    }

    public function withLifecycle(
        ToolInvocationCallerDispositionEnum $callerDisposition,
        ToolInvocationExecutionObservationEnum $executionObservation,
    ): self {
        ToolInvocationLifecycleTransition::assertAllowed(
            $this->callerDisposition,
            $this->executionObservation,
            $callerDisposition,
            $executionObservation,
        );

        return new self(
            deferredId: $this->deferredId,
            runId: $this->runId,
            turnNo: $this->turnNo,
            stepId: $this->stepId,
            attempt: $this->attempt,
            idempotencyKey: $this->idempotencyKey,
            toolCallId: $this->toolCallId,
            toolName: $this->toolName,
            arguments: $this->arguments,
            orderIndex: $this->orderIndex,
            toolIdempotencyKey: $this->toolIdempotencyKey,
            mode: $this->mode,
            timeoutSeconds: $this->timeoutSeconds,
            maxParallelism: $this->maxParallelism,
            assistantMessage: $this->assistantMessage,
            argSchema: $this->argSchema,
            toolsRef: $this->toolsRef,
            invocationId: $this->invocationId,
            controllerRunId: $this->controllerRunId,
            executionOwnerKind: $this->executionOwnerKind,
            executionOwnerReference: $this->executionOwnerReference,
            originalExecutionMode: $this->originalExecutionMode,
            deadlineMetadata: $this->deadlineMetadata,
            originWakeGeneration: $this->originWakeGeneration,
            rootWakeGeneration: $this->rootWakeGeneration,
            envelopeFingerprint: $this->envelopeFingerprint,
            callerDisposition: $callerDisposition,
            executionObservation: $executionObservation,
        );
    }
}
