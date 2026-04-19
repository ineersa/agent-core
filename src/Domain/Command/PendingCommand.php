<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Domain\Command;

/**
 * PendingCommand represents a durable, immutable command record queued for asynchronous execution within the agent core domain. It encapsulates execution metadata including run identifiers, idempotency keys, and optional configuration payloads to ensure reliable processing.
 */
final readonly class PendingCommand
{
    /**
     * Initializes the command with run ID, kind, idempotency key, and optional payload and options.
     *
     * @param array<string, mixed>      $payload
     * @param array{cancel_safe?: bool} $options
     */
    public function __construct(
        public string $runId,
        public string $kind,
        public string $idempotencyKey,
        public array $payload = [],
        public array $options = [],
    ) {
    }
}
