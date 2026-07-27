<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Contract\ContextBudget;

/**
 * Optional pre-LLM policy that decides whether a normal agent turn should
 * carry a transient wrap-up reminder.
 *
 * CodingAgent implements this contract so AgentCore can schedule reminder
 * transport without importing CodingAgent config, catalog, or event scanners.
 *
 * Compaction/summarization invocations are excluded by call-site: only the
 * shared normal ExecuteLlmStep path consults this policy.
 */
interface PreLlmContextBudgetReminderPolicyInterface
{
    /**
     * Decide whether the next normal LLM step should include a wrap-up reminder.
     *
     * Returns null when usage/window metadata is unavailable, thresholds are
     * not crossed, or the eligible threshold was already delivered for the
     * current post-compaction episode.
     *
     * @param string      $runId       Run whose committed events are scanned
     * @param string|null $activeModel Canonical run model for catalog context-window fallback
     */
    public function decide(string $runId, ?string $activeModel = null): ?ContextBudgetReminderDecision;
}
