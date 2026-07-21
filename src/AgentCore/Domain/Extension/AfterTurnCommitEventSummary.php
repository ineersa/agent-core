<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Domain\Extension;

final readonly class AfterTurnCommitEventSummary
{
    /**
     * @param array<string, mixed> $payload
     * @param string|null          $createdAt Iso8601 timestamp from the original RunEvent when available
     */
    public function __construct(
        public int $seq,
        public string $type,
        public array $payload = [],
        public int $turnNo = 0,
        public ?string $createdAt = null,
    ) {
    }
}
