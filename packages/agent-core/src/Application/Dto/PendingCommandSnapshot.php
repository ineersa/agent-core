<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Application\Dto;

final readonly class PendingCommandSnapshot
{
    /**
     * @param list<string> $payloadKeys
     */
    public function __construct(
        public string $kind,
        public string $idempotencyKey,
        public array $payloadKeys,
        public bool $cancelSafe,
    ) {
    }
}
