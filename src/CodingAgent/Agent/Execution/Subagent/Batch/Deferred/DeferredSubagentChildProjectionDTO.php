<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred;

use Ineersa\CodingAgent\Agent\Execution\Subagent\ChildRun\Deferred\DeferredChildRunLifecycleProjectionDTO;

/**
 * Durable child row within a deferred subagent batch (Piece 4A/4B).
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
        public ?DeferredChildRunLifecycleProjectionDTO $childLifecycleProjection,
        public ?\DateTimeImmutable $startedAt,
        public ?\DateTimeImmutable $terminalCompletedAt,
        public ?string $terminalStatus,
        public int $projectionVersion,
    ) {
    }
}
