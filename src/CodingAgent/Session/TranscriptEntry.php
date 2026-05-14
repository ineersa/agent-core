<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Session;

/**
 * A single entry in the TUI transcript.
 *
 * Immutable DTO stored as a JSONL line in the transcript file.
 * Represents both user messages and agent responses.
 */
final readonly class TranscriptEntry
{
    /**
     * @param string               $role      'user', 'assistant', 'tool', 'system', 'error'
     * @param string               $text      Display text
     * @param array<string, mixed> $meta      Optional metadata (runId, seq, tool name, etc.)
     * @param \DateTimeImmutable   $createdAt When this entry was created
     */
    public function __construct(
        public string $role,
        public string $text,
        public array $meta = [],
        public \DateTimeImmutable $createdAt = new \DateTimeImmutable(),
    ) {
    }

    /**
     * @return array{role: string, text: string, meta: array<string, mixed>, created_at: string}
     */
    public function toArray(): array
    {
        return [
            'role' => $this->role,
            'text' => $this->text,
            'meta' => $this->meta,
            'created_at' => $this->createdAt->format('c'),
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $createdAt = isset($data['created_at']) && \is_string($data['created_at'])
            ? new \DateTimeImmutable($data['created_at'])
            : new \DateTimeImmutable();

        return new self(
            role: (string) ($data['role'] ?? ''),
            text: (string) ($data['text'] ?? ''),
            meta: (array) ($data['meta'] ?? []),
            createdAt: $createdAt,
        );
    }
}
