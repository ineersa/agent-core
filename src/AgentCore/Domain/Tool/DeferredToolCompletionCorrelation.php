<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Domain\Tool;

/**
 * Durable snapshot of the original ExecuteToolCall envelope for later completion.
 */
final readonly class DeferredToolCompletionCorrelation
{
    /**
     * @param array<string, mixed>      $arguments
     * @param array<string, mixed>|null $assistantMessage
     * @param array<string, mixed>|null $argSchema
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
    ) {
    }
}
