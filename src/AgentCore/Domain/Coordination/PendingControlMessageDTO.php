<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Domain\Coordination;

/** One durable control obligation awaiting broker delivery. */
final readonly class PendingControlMessageDTO
{
    public function __construct(
        public string $runId,
        public string $identity,
        public string $destination,
        public object $payload,
    ) {
    }
}
