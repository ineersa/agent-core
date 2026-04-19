<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Domain\Tool;

/**
 * Represents the immutable result of a platform tool invocation, capturing assistant message deltas, usage statistics, and stop reasons. Designed as a readonly value object to ensure data integrity across the domain layer.
 */
final readonly class PlatformInvocationResult
{
    /**
     * Initializes the invocation result with assistant message, deltas, usage, stop reason, and error data.
     *
     * @param array<string, mixed>|null  $assistantMessage
     * @param list<array<string, mixed>> $deltas
     * @param array<string, int|float>   $usage
     * @param array<string, mixed>|null  $error
     */
    public function __construct(
        public ?array $assistantMessage,
        public array $deltas = [],
        public array $usage = [],
        public ?string $stopReason = null,
        public ?array $error = null,
    ) {
    }

    /**
     * Converts the invocation result into a plain array representation.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'assistant_message' => $this->assistantMessage,
            'deltas' => $this->deltas,
            'usage' => $this->usage,
            'stop_reason' => $this->stopReason,
            'error' => $this->error,
        ];
    }
}
