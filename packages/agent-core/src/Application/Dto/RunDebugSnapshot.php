<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Application\Dto;

final readonly class RunDebugSnapshot
{
    /**
     * @param list<PendingCommandSnapshot> $pendingCommands
     * @param array<string, mixed>|null    $metrics
     */
    public function __construct(
        public string $runId,
        public bool $exists,
        public ?RunStateSnapshot $state,
        public ReplayIntegrity $integrity,
        public ?HotPromptStateSnapshot $hotPromptState,
        public array $pendingCommands = [],
        public ?array $metrics = null,
    ) {
    }
}
