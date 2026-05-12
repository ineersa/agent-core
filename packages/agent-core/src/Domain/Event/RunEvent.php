<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Domain\Event;

readonly class RunEvent
{
    /**
     * Initializes the run event with run ID, sequence, turn number, type, and optional payload.
     *
     * @param array<string, mixed> $payload
     */
    public function __construct(
        public string $runId,
        public int $seq,
        public int $turnNo,
        public string $type,
        public array $payload = [],
        public \DateTimeImmutable $createdAt = new \DateTimeImmutable(),
    ) {
    }

    public function isExtensionEvent(string $prefix = 'ext_'): bool
    {
        return str_starts_with($this->type, $prefix);
    }

    /**
     * Factory method creating a new RunEvent instance with the provided parameters.
     *
     * @param array<string, mixed> $payload
     */
    public static function extension(
        string $runId,
        int $seq,
        int $turnNo,
        string $type,
        array $payload = [],
        string $prefix = 'ext_',
        ?\DateTimeImmutable $createdAt = null,
    ): self {
        if (!str_starts_with($type, $prefix)) {
            throw new \InvalidArgumentException(\sprintf('Custom event type "%s" must use "%s" prefix.', $type, $prefix));
        }

        return new self(
            runId: $runId,
            seq: $seq,
            turnNo: $turnNo,
            type: $type,
            payload: $payload,
            createdAt: $createdAt ?? new \DateTimeImmutable(),
        );
    }
}
