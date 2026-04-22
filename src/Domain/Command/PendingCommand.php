<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Domain\Command;

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
