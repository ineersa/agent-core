<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Api\Dto;

/**
 * Represents a single event emitted during a run stream, carrying sequence and turn metadata alongside a typed payload. This immutable DTO structures data for transport across the API boundary without enforcing business logic.
 */
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
