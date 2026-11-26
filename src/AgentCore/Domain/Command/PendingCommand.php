<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Domain\Command;

final readonly class PendingCommand
{
    /**
     * Initializes the command with run ID, kind, idempotency key, and optional payload.
     *
     * @param array<string, mixed> $payload
     */
    public function __construct(
        public string $runId,
        public string $kind,
        public string $idempotencyKey,
        public array $payload = [],
    ) {
    }

    /** @param array{runId: string, kind: string, idempotencyKey: string, payload: array<string, mixed>} $data */
    public function __unserialize(array $data): void
    {
        // Pending rows can outlive an upgrade. Ignore the old cancellation options.
        $this->runId = $data['runId'];
        $this->kind = $data['kind'];
        $this->idempotencyKey = $data['idempotencyKey'];
        $this->payload = $data['payload'];
    }
}
