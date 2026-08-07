<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Compaction;

use Ineersa\AgentCore\Contract\Compaction\CompactionEligibilityPolicyInterface;
use Ineersa\CodingAgent\Agent\Execution\SubagentRunMetadataReader;

/**
 * Compaction eligibility for agent child runs (fork + subagent).
 *
 * Canonical child detection is {@see SubagentRunMetadataReader::isAgentChild()}
 * ({@code RunStarted} metadata {@code session.kind=agent_child}). Both fork and
 * subagent children share that kind; forks additionally set {@code child_kind=fork}.
 *
 * When false, automatic scheduling paths must not dispatch CompactRun, and
 * CompactRunHandler must no-op without lifecycle events or preparation work.
 * Parent runs remain eligible. Parent-side fork snapshot compaction uses
 * {@see CompactionService::compactMessages} and never consults this policy.
 */
final readonly class ChildRunCompactionEligibilityPolicy implements CompactionEligibilityPolicyInterface
{
    public function __construct(
        private SubagentRunMetadataReader $metadataReader,
    ) {
    }

    public function isCompactionAllowed(string $runId): bool
    {
        return !$this->metadataReader->isAgentChild($runId);
    }
}
