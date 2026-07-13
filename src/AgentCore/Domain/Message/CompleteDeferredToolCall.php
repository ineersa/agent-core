<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Domain\Message;

/**
 * Completes a previously deferred tool execution with a canonical tool result payload.
 */
final readonly class CompleteDeferredToolCall extends AbstractAgentBusMessage
{
    /**
     * @param array<int, array<string, mixed>> $content
     * @param array<string, mixed>|null        $details
     * @param array<string, mixed>|null        $arguments
     * @param array<string, mixed>|null        $error
     */
    public function __construct(
        string $runId,
        int $turnNo,
        string $stepId,
        int $attempt,
        string $idempotencyKey,
        public string $deferredId,
        public string $toolCallId,
        public string $toolName,
        public array $content,
        public ?array $details = null,
        public bool $isError = false,
        public ?array $error = null,
        public ?string $toolIdempotencyKey = null,
        public ?string $mode = null,
        public ?array $arguments = null,
    ) {
        parent::__construct($runId, $turnNo, $stepId, $attempt, $idempotencyKey);
    }
}
