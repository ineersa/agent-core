<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred;

use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactStatusEnum;
use Ineersa\CodingAgent\Agent\Execution\SubagentChildProgressSummary;

/**
 * Normalized durable child row state before parallel snapshot/report assembly (Piece 4B).
 */
final readonly class DeferredSubagentBatchChildProgressStateDTO
{
    public function __construct(
        public bool $terminal,
        public AgentArtifactStatusEnum $artifactStatus,
        public string $message,
        public int $turnNo,
        public ?SubagentChildProgressSummary $enrichment,
    ) {
    }
}
