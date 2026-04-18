<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Domain\Message;

/**
 * Bundle-level message DTO with JS parity fields.
 */
final readonly class AgentMessage
{
    /**
     * @param array<int, array<string, mixed>> $content
     * @param array<string, mixed>             $metadata
     */
    public function __construct(
        public string $role,
        public array $content,
        public ?\DateTimeImmutable $timestamp = null,
        public ?string $name = null,
        public ?string $toolCallId = null,
        public ?string $toolName = null,
        public mixed $details = null,
        public bool $isError = false,
        public array $metadata = [],
    ) {
    }

    public function isCustomRole(): bool
    {
        return !\in_array($this->role, ['system', 'user', 'assistant', 'tool'], true);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $payload = [
            'role' => $this->role,
            'content' => $this->content,
            'is_error' => $this->isError,
        ];

        if (null !== $this->timestamp) {
            $payload['timestamp'] = $this->timestamp->format(\DATE_ATOM);
        }

        if (null !== $this->name) {
            $payload['name'] = $this->name;
        }

        if (null !== $this->toolCallId) {
            $payload['tool_call_id'] = $this->toolCallId;
        }

        if (null !== $this->toolName) {
            $payload['tool_name'] = $this->toolName;
        }

        if (null !== $this->details) {
            $payload['details'] = $this->details;
        }

        if ([] !== $this->metadata) {
            $payload['metadata'] = $this->metadata;
        }

        return $payload;
    }
}
