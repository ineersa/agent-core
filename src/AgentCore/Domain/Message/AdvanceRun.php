<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Domain\Message;

final readonly class AdvanceRun extends AbstractAgentBusMessage implements RunControlTransitionMessageInterface
{
    /**
     * Initializes the advance run event with run, turn, step, attempt, and idempotency identifiers.
     *
     * @param array<string, mixed> $payload
     */
    public function __construct(
        string $runId,
        int $turnNo,
        string $stepId,
        int $attempt,
        string $idempotencyKey,
        public array $payload = [],
        public ?\Ineersa\AgentCore\Domain\Tool\Completion\ToolCompletionDeliveryFenceDTO $completionFence = null,
        public bool $completionMailboxDrain = false,
        public ?\Ineersa\AgentCore\Domain\Tool\Completion\ToolCompletionDeliveryReferenceDTO $appliedCompletion = null,
    ) {
        parent::__construct($runId, $turnNo, $stepId, $attempt, $idempotencyKey);
        if ($completionMailboxDrain && null === $completionFence) {
            throw new \InvalidArgumentException('Completion mailbox continuation requires a captured fence.');
        }
        if (null !== $appliedCompletion && (null === $completionFence || $appliedCompletion->destinationRunId !== $runId || !\Ineersa\AgentCore\Domain\Tool\Completion\ToolCompletionDeliveryFenceDTO::same($completionFence, $appliedCompletion->deliveryFence))) {
            throw new \InvalidArgumentException('Applied completion continuation requires its exact recipient fence.');
        }
    }
}
