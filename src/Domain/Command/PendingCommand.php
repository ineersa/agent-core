<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Domain\Command;

final readonly class PendingCommand
{
    /**
     * @param array<string, mixed>      $payload
     * @param array{cancel_safe?: bool} $options
     */
    public function __construct(
        public string $runId,
        public string $kind,
        public string $idempotencyKey,
        public array $payload = [],
        public array $options = [],
    ) {
    }
}
