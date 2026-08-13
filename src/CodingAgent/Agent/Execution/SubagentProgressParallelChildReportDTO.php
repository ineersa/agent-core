<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Execution;

use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactStatusEnum;

/**
 * One parallel-batch child report fed into {@see SubagentProgressSnapshotBuilder::parallelSnapshot()}.
 */
final readonly class SubagentProgressParallelChildReportDTO
{
    public function __construct(
        public int $index,
        public string $agentName,
        public string $task,
        public string $artifactId,
        public string $agentRunId,
        public bool $terminal,
        public ?AgentArtifactStatusEnum $status,
        public string $message = '',
        public ?string $model = null,
        public ?string $reasoning = null,
    ) {
    }
}
