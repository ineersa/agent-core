<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Domain\Message;

use Ineersa\AgentCore\Domain\Run\PendingHumanInputRequestDTO;

/**
 * Canonical tool-worker → run_control envelope.
 *
 * Ordinary completed tool outcomes use `$result` / `$isError`.
 * When `$pendingHumanInput` is non-null this envelope is a typed NON-TERMINAL
 * human-input suspension: it must not be collected as a finished tool result
 * and must not append a tool message or mark pendingToolCalls complete.
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

    public function isHumanInputSuspension(): bool
    {
        return null !== $this->pendingHumanInput;
    }
}
