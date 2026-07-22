<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Domain\Extension;

final readonly class AfterTurnCommitEventSummary
{
    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(
        public int $seq,
        public string $type,
        public array $payload = [],
    ) {
    }
}
