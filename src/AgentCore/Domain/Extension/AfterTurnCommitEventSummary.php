<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Domain\Extension;

final readonly class AfterTurnCommitEventSummary
{
    /**
     * @param array<string, mixed>|null $payload Optional event payload for commit-time
     *                                           hook subscribers that need to inspect
     *                                           event data (e.g. SafeGuard approval routing).
     */
    public function __construct(
        public int $seq,
        public string $type,
        public ?array $payload = null,
    ) {
    }
}
