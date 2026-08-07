<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Contract\Compaction;

/**
 * Policy gate for whether a run may schedule or execute context compaction.
 *
 * CodingAgent implements this with run-kind metadata (agent children never
 * compact). AgentCore pipeline code depends only on this contract so overflow
 * recovery and other core paths can ask without importing CodingAgent types.
 *
 * Parent-side snapshot compaction ({@see CompactionServiceInterface::compactMessages}
 * with trigger {@code fork}) does not consult this policy — it compacts the
 * parent history before a child launches, not the child run itself.
 */
interface CompactionEligibilityPolicyInterface
{
    /**
     * Whether CompactRun may be scheduled or executed for this run.
     *
     * Returns false for fork/subagent child runs ({@code session.kind=agent_child}).
     * Returns true for ordinary parent sessions and when eligibility cannot be
     * determined as a child (missing RunStarted metadata).
     */
    public function isCompactionAllowed(string $runId): bool;
}
