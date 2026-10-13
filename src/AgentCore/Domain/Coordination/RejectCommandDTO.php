<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Domain\Coordination;

final readonly class RejectCommandDTO
{
    public function __construct(public string $runId, public string $idempotencyKey, public string $reason)
    {
    }
}
