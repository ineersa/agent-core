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
        public ?string $acceptedCompletionId = null,
        public ?string $acceptedResultFingerprint = null,
        public ?Completion\ToolCompletionDeliveryFenceDTO $deliveryFence = null,
    ) {
        if (null !== $this->executionOwnerKind && '' === $this->executionOwnerKind) {
            throw new \InvalidArgumentException('Execution owner kind cannot be empty when present.');
        }
        if (null !== $this->executionOwnerReference && null === $this->executionOwnerKind) {
            throw new \InvalidArgumentException('Execution owner reference requires an owner kind.');
        }
        if (null !== $this->executionOwnerReference && $this->executionOwnerReference->kind !== $this->executionOwnerKind) {
            throw new \InvalidArgumentException('Execution owner kind must match the owner reference kind.');
        }
        if (null !== $this->originWakeGeneration && $this->originWakeGeneration < 0) {
            throw new \InvalidArgumentException('Origin wake generation cannot be negative.');
        }
        if (null !== $this->rootWakeGeneration && $this->rootWakeGeneration < 0) {
            throw new \InvalidArgumentException('Root wake generation cannot be negative.');
        }
        if (null !== $this->envelopeFingerprint) {
            $expectedFingerprint = ToolInvocationEnvelopeFingerprint::fromCorrelation($this);
            if ($this->envelopeFingerprint !== $expectedFingerprint) {
                throw new \InvalidArgumentException('Envelope fingerprint must match the immutable execution envelope.');
            }
        }
        if ((null === $this->acceptedCompletionId) !== (null === $this->acceptedResultFingerprint)) {
            throw new \InvalidArgumentException('Accepted completion id and result fingerprint must be recorded together.');
        }
        if (null !== $this->acceptedCompletionId && '' === $this->acceptedCompletionId) {
            throw new \InvalidArgumentException('Accepted completion id cannot be empty when present.');
        }
        if (null !== $this->acceptedResultFingerprint && '' === $this->acceptedResultFingerprint) {
            throw new \InvalidArgumentException('Accepted result fingerprint cannot be empty when present.');
        }
        if (null !== $this->acceptedCompletionId
            && ToolInvocationExecutionObservationEnum::Terminal !== $this->executionObservation
            && ToolInvocationExecutionObservationEnum::Unknown !== $this->executionObservation) {
            throw new \InvalidArgumentException('Accepted completion identity requires a terminal or unknown execution observation.');
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

    /**
     * Compare accepted immutable owner/controller/alias/deadline bindings.
     *
     * Presentation PID and log path never authorize lookup or lifecycle writes.
     * Ordinary foreground rows without these bindings remain compatible.
     */
    public function assertAcceptedImmutableBindingMatches(self $candidate): void
    {
        $this->assertEnvelopeMatches($candidate);

        if ($this->executionOwnerKind !== $candidate->executionOwnerKind) {
            throw new \RuntimeException(\sprintf('Deferred execution owner kind mismatch for run "%s" tool call "%s".', $this->runId, $this->toolCallId));
        }

        $expectedOwner = $this->executionOwnerReference;
        $actualOwner = $candidate->executionOwnerReference;
        if ((null === $expectedOwner) !== (null === $actualOwner)
            || (null !== $expectedOwner && null !== $actualOwner
                && ($expectedOwner->kind !== $actualOwner->kind || $expectedOwner->recordId !== $actualOwner->recordId))) {
            throw new \RuntimeException(\sprintf('Deferred execution owner record mismatch for run "%s" tool call "%s".', $this->runId, $this->toolCallId));
        }

        if ($this->controllerRunId !== $candidate->controllerRunId) {
            throw new \RuntimeException(\sprintf('Deferred controller run mismatch for run "%s" tool call "%s".', $this->runId, $this->toolCallId));
        }

        if ($this->invocationId !== $candidate->invocationId) {
            throw new \RuntimeException(\sprintf('Deferred invocation alias mismatch for run "%s" tool call "%s".', $this->runId, $this->toolCallId));
        }

        if ($this->originalExecutionMode !== $candidate->originalExecutionMode) {
            throw new \RuntimeException(\sprintf('Deferred original execution mode mismatch for run "%s" tool call "%s".', $this->runId, $this->toolCallId));
        }

        if ($this->deadlineMetadata !== $candidate->deadlineMetadata) {
            throw new \RuntimeException(\sprintf('Deferred deadline metadata mismatch for run "%s" tool call "%s".', $this->runId, $this->toolCallId));
        }
        if (!Completion\ToolCompletionDeliveryFenceDTO::same($this->deliveryFence, $candidate->deliveryFence)) {
            throw new \RuntimeException('Deferred delivery permission mismatch.');
        }
    }

    public function withLifecycle(
        ToolInvocationCallerDispositionEnum $callerDisposition,
        ToolInvocationExecutionObservationEnum $executionObservation,
        ?string $acceptedCompletionId = null,
        ?string $acceptedResultFingerprint = null,
        ?Completion\ToolCompletionDeliveryFenceDTO $deliveryFence = null,
    ): self {
        if (null !== $deliveryFence) {
            if (null !== $this->deliveryFence && !Completion\ToolCompletionDeliveryFenceDTO::same($this->deliveryFence, $deliveryFence)) {
                throw new \RuntimeException('Accepted completion delivery fence is immutable.');
            }
            if (null === $this->deliveryFence && (ToolInvocationCallerDispositionEnum::ForegroundPending !== $this->callerDisposition || ToolInvocationCallerDispositionEnum::BackgroundAcknowledged !== $callerDisposition)) {
                throw new \RuntimeException('Completion delivery permission can only be captured at detachment.');
            }
        }
        ToolInvocationLifecycleTransition::assertAllowed(
            $this->callerDisposition,
            $this->executionObservation,
            $callerDisposition,
            $executionObservation,
        );

        $nextCompletionId = $this->acceptedCompletionId;
        $nextResultFingerprint = $this->acceptedResultFingerprint;
        if (null !== $acceptedCompletionId || null !== $acceptedResultFingerprint) {
            if (null === $acceptedCompletionId || null === $acceptedResultFingerprint) {
                throw new \InvalidArgumentException('Accepted completion id and result fingerprint must be supplied together.');
            }
            if (null !== $this->acceptedCompletionId) {
                if ($this->acceptedCompletionId !== $acceptedCompletionId
                    || $this->acceptedResultFingerprint !== $acceptedResultFingerprint) {
                    throw new \RuntimeException(\sprintf('Conflicting accepted completion for run "%s" tool call "%s".', $this->runId, $this->toolCallId));
                }
            } else {
                $expectedCompletionId = ToolInvocationAcceptedCompletionIdentity::completionIdFromCorrelation($this);
                if ($acceptedCompletionId !== $expectedCompletionId) {
                    throw new \InvalidArgumentException('Accepted completion id must match the deterministic invocation receipt.');
                }
                $nextCompletionId = $acceptedCompletionId;
                $nextResultFingerprint = $acceptedResultFingerprint;
            }
        }

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
            acceptedCompletionId: $nextCompletionId,
            acceptedResultFingerprint: $nextResultFingerprint,
            deliveryFence: $deliveryFence ?? $this->deliveryFence,
        );
    }
}
