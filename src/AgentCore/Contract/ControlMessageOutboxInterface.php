<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Contract;

use Ineersa\AgentCore\Domain\Coordination\PendingControlMessageDTO;

/** Small durable control-message obligations. Invocations stay on the ledger. */
interface ControlMessageOutboxInterface
{
    /** Persist one stable identity/destination obligation. Idempotent for the same identity. */
    public function enqueue(string $runId, string $identity, string $destination, object $payload): void;

    /**
     * @return list<PendingControlMessageDTO>
     */
    public function pendingForRun(string $runId): array;

    public function acknowledge(string $runId, string $identity): void;
}
