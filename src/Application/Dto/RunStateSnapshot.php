<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Application\Dto;

final readonly class RunStateSnapshot
{
    public function __construct(
        public string $status,
        public int $version,
        public int $turnNo,
        public int $lastSeq,
        public ?string $activeStepId,
        public bool $retryableFailure,
        public int $messagesCount,
        public int $pendingToolCalls,
    ) {
    }
}
