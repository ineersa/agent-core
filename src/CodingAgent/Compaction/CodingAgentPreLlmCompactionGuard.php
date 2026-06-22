<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Compaction;

use Ineersa\AgentCore\Contract\Compaction\PreLlmCompactionGuardInterface;
use Ineersa\CodingAgent\Config\CompactionConfig;

/**
 * CodingAgent implementation of the pre-LLM compaction guard.
 *
 * Resolves per-provider/per-model runtime settings and checks the latest
 * provider-reported context token count against compact_after_tokens.
 * Uses committed llm_step_completed event usage as the authoritative
 * context size — NOT the text-only CompactionTokenEstimator.
 *
 * Registered in the container so {@see \Ineersa\AgentCore\Application\Pipeline\AdvanceRunHandler}
 * can inject the AgentCore contract without depending on CodingAgent.
 *
 * Not readonly because it carries a small in-process dedup map that
 * prevents repeated pre-LLM compaction for the same run+turnNo after
 * a previous pre-LLM compaction already completed.
 */
final class CodingAgentPreLlmCompactionGuard implements PreLlmCompactionGuardInterface
{
    /**
     * In-process dedup: run+turnNo keys for which pre-LLM compaction has
     * already been attempted (and either succeeded or failed).  Prevents
     * infinite AdvanceRun → compact → AdvanceRun loops when tokens remain
     * above threshold after compaction.
     *
     * Cleared on process restart (intentional — the next process should
     * re-evaluate).
     *
     * @var array<string, true>
     */
    private array $preLlmCompacted = [];

    /**
     * Run-level dedup: run IDs for which pre-LLM compaction has already
     * been dispatched.  Prevents the guard from firing a second pre-LLM
     * compaction for the same run — even at a different turnNo — when
     * the first compaction is still in flight or the run was re-advanced
     * by a follow-up/steer command before the async compaction worker
     * completed.
     *
     * This is a belt-and-suspenders guard on top of the turn-level dedup
     * and the activeStepId compact-* prefix check in shouldCompactBeforeLlmStep.
     * In practice, AdvanceRun can arrive through multiple buses
     * (agent.command.bus from ApplyCommandHandler postCommit callbacks,
     * agent.execution.bus from handler effects), and the state visible to
     * each handler may differ depending on CAS commit ordering.
     *
     * Cleared on process restart.
     *
     * @var array<string, true>
     */
    private array $preLlmCompactedByRun = [];

    public function __construct(
        private readonly CompactionConfig $compactionConfig,
        private readonly ProviderContextUsageResolver $providerUsageResolver,
        private readonly ActiveModelResolverInterface $modelResolver,
    ) {
    }

    public function shouldCompactBeforeLlmStep(
        string $runId,
        int $nextTurnNo,
        array $messages,
        ?string $activeStepId,
    ): bool {
        // Run-level guard: prevent ANY second pre-LLM compaction for the
        // same run.  When the pre-LLM guard fires, the AdvanceRun is
        // replaced with a CompactRun effect.  After compaction succeeds,
        // CompactionStepResultHandler dispatches another AdvanceRun to
        // continue.  If token count is still above threshold, the guard
        // would fire again — this dedup prevents that loop.
        //
        // Using runId alone (not runId|turnNo) ensures that follow-up or
        // steer commands applied while the first compaction is in flight
        // cannot trigger a second pre-LLM compaction through a different
        // AdvanceRun path (e.g., ApplyCommandHandler's postCommit callback
        // dispatching AdvanceRun to agent.command.bus).
        if (isset($this->preLlmCompactedByRun[$runId])) {
            return false;
        }

        // Turn-level guard: prevent repeated pre-LLM compaction for the
        // same run+turnNo (additional safety within the same turn).
        $dedupKey = \sprintf('%s|%d', $runId, $nextTurnNo);
        if (isset($this->preLlmCompacted[$dedupKey])) {
            return false;
        }

        // Resolve per-provider/per-model runtime settings.
        $activeModel = $this->modelResolver->getActiveModel($runId);
        $runtimeSettings = $this->compactionConfig->resolveRuntimeSettings($activeModel);

        if (!$runtimeSettings->autoEnabled) {
            return false;
        }

        // Do not compact when another compaction is already in flight.
        if (null !== $activeStepId && str_starts_with($activeStepId, 'compact-')) {
            return false;
        }

        // Check the provider-measured context token count against the
        // compact_after_tokens threshold.  The text-only estimator is NOT
        // used as the trigger baseline — it undercounts real provider
        // context by omitting tool schemas, JSON envelope, and provider-
        // specific overhead.  No provider measurement means no auto-compaction.
        $effectiveTokens = $this->providerUsageResolver->getLatestInputTokens($runId);

        if (null !== $effectiveTokens && $effectiveTokens > $runtimeSettings->compactAfterTokens) {
            $this->preLlmCompacted[$dedupKey] = true;
            $this->preLlmCompactedByRun[$runId] = true;

            return true;
        }

        return false;
    }
}
