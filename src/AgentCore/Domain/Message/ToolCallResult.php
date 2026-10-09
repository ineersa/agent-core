<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Domain\Message;

use Ineersa\AgentCore\Domain\Run\PendingHumanInputRequestDTO;
use Ineersa\AgentCore\Domain\Tool\ToolResultText;

/**
 * Canonical tool-worker → run_control envelope.
 *
 * Ordinary completed tool outcomes use `$result` / `$isError`.
 * When `$pendingHumanInput` is non-null this envelope is a typed NON-TERMINAL
 * human-input suspension: it must not be collected as a finished tool result
 * and must not append a tool message or mark pendingToolCalls complete.
 *
 * Pending human input is included in durable worker results so redelivery
 * preserves suspension semantics.
 */
final readonly class ToolCallResult extends AbstractAgentBusMessage
{
    /**
     * @param array<string, mixed>|null $error
     */
    public function __construct(
        string $runId,
        int $turnNo,
        string $stepId,
        int $attempt,
        string $idempotencyKey,
        public string $toolCallId,
        public int $orderIndex,
        public mixed $result = null,
        public bool $isError = false,
        public ?array $error = null,
        public ?PendingHumanInputRequestDTO $pendingHumanInput = null,
    ) {
        parent::__construct($runId, $turnNo, $stepId, $attempt, $idempotencyKey);
    }

    /** Finalize before dispatch and again on owner admission after PHP deserialization. */
    public function finalized(): self
    {
        $failure = ToolResultText::failureMessage($this->result)
            ?? ToolResultText::failureMessage($this->error)
            ?? ToolResultText::failureMessage($this->pendingHumanInput?->waitingHumanEventPayload())
            ?? ToolResultText::failureMessage($this->pendingHumanInput?->questionId);
        if (null === $failure) {
            return $this;
        }

        return $this->withRepresentationFailure($failure);
    }

    public function withRepresentationFailure(string $failure): self
    {
        $result = [
            'content' => [['type' => 'text', 'text' => $failure]],
            'details' => ['retryable' => false],
        ];
        if (\is_array($this->result)) {
            foreach (['tool_name', 'arguments', 'mode', 'tool_idempotency_key', 'standalone'] as $field) {
                if (\array_key_exists($field, $this->result) && ToolResultText::isValid($this->result[$field])) {
                    $result[$field] = $this->result[$field];
                }
            }
        }

        return new self(
            $this->runId(), $this->turnNo(), $this->stepId(), $this->attempt(), $this->idempotencyKey(),
            $this->toolCallId, $this->orderIndex, $result, true,
            ['message' => $failure, 'retryable' => false],
        );
    }

    public function isHumanInputSuspension(): bool
    {
        return null !== $this->pendingHumanInput;
    }
}
