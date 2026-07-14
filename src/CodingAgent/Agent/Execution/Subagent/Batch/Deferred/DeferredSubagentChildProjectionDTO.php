<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred;

/**
 * Durable child row within a deferred subagent batch (Piece 4A).
 */
final readonly class DeferredSubagentChildProjectionDTO
{
    public function __construct(
        public string $batchLifecycleId,
        public int $batchIndex,
        public string $childRunId,
        public string $artifactId,
        public string $agentName,
        public string $task,
        public ?string $definitionModel,
        public DeferredSubagentChildLaunchStatusEnum $launchStatus,
        public int $childEventCursor,
        public ?\DateTimeImmutable $startedAt,
        public int $projectionVersion,
    ) {
    }
}
