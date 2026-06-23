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
        // One-shot guard: prevent repeated pre-LLM compaction for the
        // same run+turnNo.  When the pre-LLM guard fires, the AdvanceRun
        // is replaced with a CompactRun effect.  After compaction succeeds,
        // CompactionStepResultHandler dispatches another AdvanceRun to
        // continue.  If token count is still above threshold, the guard
        // would fire again — this dedup prevents that loop.
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
        $effectiveTokens = $this->providerUsageResolver->getLatestEligibleInputTokens($runId);

        if (null !== $effectiveTokens && $effectiveTokens > $runtimeSettings->compactAfterTokens) {
            $this->preLlmCompacted[$dedupKey] = true;

            return true;
        }

        return false;
    }
}
