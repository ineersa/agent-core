<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Domain\Tool;

final readonly class PlatformInvocationResult
{
    /**
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
