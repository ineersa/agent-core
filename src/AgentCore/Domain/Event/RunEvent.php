<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Domain\Event;

readonly class RunEvent
{
    /** Sentinel seq for append drafts; committed stores replace with allocated seq. */
    public const APPEND_DRAFT_SEQ = 0;

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

    /**
     * Draft event for canonical append paths. Seq is assigned by the event store on commit.
     *
     * @param array<string, mixed> $payload
     */
    public static function forAppend(
        string $runId,
        int $turnNo,
        string $type,
        array $payload = [],
        ?\DateTimeImmutable $createdAt = null,
    ): self {
        return new self(
            runId: $runId,
            seq: self::APPEND_DRAFT_SEQ,
            turnNo: $turnNo,
            type: $type,
            payload: $payload,
            createdAt: $createdAt ?? new \DateTimeImmutable(),
        );
    }
}
