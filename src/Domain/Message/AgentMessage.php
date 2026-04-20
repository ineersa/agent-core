<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Domain\Message;

/**
 * Represents a canonical transcript message at bundle boundaries, including role/content and JS-parity fields for tool calls, errors, and metadata.
 */
final readonly class AgentMessage
{
    /**
     * Initializes the message with role, content, and optional metadata fields.
     *
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
     * Converts the message instance into a plain array representation.
     *
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
