<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Domain\Coordination;

use Ineersa\AgentCore\Domain\Tool\DeferredToolCompletionCorrelation;
use Ineersa\AgentCore\Domain\Tool\ToolInvocationCallerDispositionEnum;
use Ineersa\AgentCore\Domain\Tool\ToolInvocationExecutionObservationEnum;

/**
 * Captured local coordination for tool-invocation lifecycle projection updates.
 * Does not enable background acknowledgment or capability exposure by itself.
 */
final readonly class RecordToolInvocationLifecycleDTO
{
    public function __construct(
        public DeferredToolCompletionCorrelation $expectedCorrelation,
        public ToolInvocationCallerDispositionEnum $callerDisposition,
        public ToolInvocationExecutionObservationEnum $executionObservation,
        public ?string $acceptedCompletionId = null,
        public ?string $acceptedResultFingerprint = null,
        public ?\Ineersa\AgentCore\Domain\Tool\Completion\ToolCompletionDeliveryFenceDTO $deliveryFence = null,
    ) {
        if ((null === $this->acceptedCompletionId) !== (null === $this->acceptedResultFingerprint)) {
            throw new \InvalidArgumentException('Accepted completion id and result fingerprint must be recorded together.');
        }
        if (null !== $this->acceptedCompletionId && '' === $this->acceptedCompletionId) {
            throw new \InvalidArgumentException('Accepted completion id cannot be empty when present.');
        }
        if (null !== $this->acceptedResultFingerprint && '' === $this->acceptedResultFingerprint) {
            throw new \InvalidArgumentException('Accepted result fingerprint cannot be empty when present.');
        }
    }

    public function assertCurrentBindingMatches(DeferredToolCompletionCorrelation $current): void
    {
        // Recovery may observe the already committed detachment. Compare the
        // captured target fence, never a freshly acquired controller permission.
        $expected = null !== $current->deliveryFence && null !== $this->deliveryFence
            ? $this->expectedCorrelation->withLifecycle($this->callerDisposition, $this->executionObservation, $this->acceptedCompletionId, $this->acceptedResultFingerprint, $this->deliveryFence)
            : $this->expectedCorrelation;
        $current->assertAcceptedImmutableBindingMatches($expected);
    }
}
