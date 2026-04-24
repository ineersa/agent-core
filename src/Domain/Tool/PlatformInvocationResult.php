<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Domain\Tool;

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
     * @return list<array<string, mixed>>
     */
    public function deltas(): array
    {
        return $this->deltas;
    }
}
