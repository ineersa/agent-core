<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Domain\Command;

use Ineersa\AgentCore\Domain\Extension\CommandCancellationOptions;

final readonly class PendingCommand
{
    /**
     * Initializes the command with run ID, kind, idempotency key, and optional payload and options.
     *
     * @param array<string, mixed> $payload
     */
    public function __construct(
        public string $runId,
        public string $kind,
        public string $idempotencyKey,
        public array $payload = [],
        public CommandCancellationOptions $options = new CommandCancellationOptions(),
    ) {
    }
}
