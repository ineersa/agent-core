<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Api\Dto;

final readonly class RunStreamEvent
{
    /**
     * Initializes the event with run ID, sequence number, turn number, type, and optional payload.
     *
     * @param array<string, mixed> $payload
     */
    public function __construct(
        public string $runId,
        public int $seq,
        public int $turnNo,
        public string $type,
        public array $payload = [],
        public \DateTimeImmutable $ts = new \DateTimeImmutable(),
    ) {
    }
}
