<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Domain\Message;

use Symfony\Component\Serializer\Attribute\Ignore;
use Symfony\Component\Serializer\Attribute\SerializedName;

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
        #[SerializedName('tool_call_id')]
        public ?string $toolCallId = null,
        #[SerializedName('tool_name')]
        public ?string $toolName = null,
        public mixed $details = null,
        #[SerializedName('is_error')]
        public bool $isError = false,
        public array $metadata = [],
    ) {
    }

    /**
     * Builds a message instance from a serialized payload.
     *
     * @param array<string, mixed> $payload
     */
    public static function fromPayload(array $payload): ?self
    {
        $role = $payload['role'] ?? null;
        $rawContent = $payload['content'] ?? null;

        if (!\is_string($role) || !\is_array($rawContent)) {
            return null;
        }

        $content = [];
        foreach ($rawContent as $contentPart) {
            if (!\is_array($contentPart)) {
                continue;
            }

            $content[] = $contentPart;
        }

        $timestamp = null;
        if (\is_string($payload['timestamp'] ?? null)) {
            try {
                $timestamp = new \DateTimeImmutable($payload['timestamp']);
            } catch (\Throwable) {
            }
        }

        return new self(
            role: $role,
            content: $content,
            timestamp: $timestamp,
            name: \is_string($payload['name'] ?? null) ? $payload['name'] : null,
            toolCallId: \is_string($payload['tool_call_id'] ?? null) ? $payload['tool_call_id'] : null,
            toolName: \is_string($payload['tool_name'] ?? null) ? $payload['tool_name'] : null,
            details: $payload['details'] ?? null,
            isError: \is_bool($payload['is_error'] ?? null) ? $payload['is_error'] : false,
            metadata: \is_array($payload['metadata'] ?? null) ? $payload['metadata'] : [],
        );
    }

    #[Ignore]
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
