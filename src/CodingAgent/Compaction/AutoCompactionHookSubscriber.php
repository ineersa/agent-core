<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Compaction;

use Ineersa\AgentCore\Contract\Compaction\CompactionServiceInterface;
use Ineersa\AgentCore\Contract\Extension\HookSubscriberInterface;
use Ineersa\AgentCore\Contract\RunStoreInterface;
use Ineersa\AgentCore\Domain\Event\RunEventTypeEnum;
use Ineersa\AgentCore\Domain\Extension\AfterTurnCommitHookContext;
use Ineersa\AgentCore\Domain\Message\CompactRun;
use Ineersa\CodingAgent\Config\CompactionConfig;
use Symfony\Component\Messenger\Exception\ExceptionInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * After-turn hook that triggers auto-compaction when the latest provider-
 * reported context token count exceeds the compact_after_tokens threshold.
 *
 * Uses committed llm_step_completed/llm_step_aborted event usage as the
 * authoritative context size — NOT the text-only CompactionTokenEstimator.
 * No provider measurement = no auto-compaction (fresh runs wait for the
 * first LLM call to produce a measurement).
 *
 * Registered as a HookSubscriberInterface (auto-tagged agent_core.hook_subscriber).
 * Runs synchronously inside RunCommit::commit() so the dispatch is a fire-and-
 * forget send to agent.command.bus — it never blocks the commit.
 *
 * Guards:
 *  - Auto disabled via compaction.auto_enabled (per-provider/per-model overrides)
 *  - In-flight compaction (activeStepId starts with compact-)
 *  - Commit contains compaction lifecycle events (avoids loops)
 *  - Commit contains tool_batch_committed (ToolCallResultHandler uses postCommit
 *    for post-tool AdvanceRun, so effectsCount is 0 but the turn will continue;
 *    auto-compaction must not interrupt an in-progress assistant/tool cycle)
 *  - Provider context tokens ≤ threshold (or no provider measurement)
 *  - In-process dedup per run (prevents double dispatch within a single process
 *    between async compaction dispatch and lifecycle commit)
 *  - Commits containing AgentCommandQueued or AgentCommandApplied
 *    (races pending follow-up command with auto-compaction)
 */
final class AutoCompactionHookSubscriber implements HookSubscriberInterface
{
    /** @var array<string, true> Run IDs with an in-flight auto dispatch (in-process dedup) */
    private array $inFlight = [];

    public function __construct(
        private readonly RunStoreInterface $runStore,
        private readonly ProviderContextUsageResolver $providerUsageResolver,
        private readonly CompactionConfig $compactionConfig,
        private readonly ActiveModelResolverInterface $modelResolver,
        private readonly MessageBusInterface $commandBus,
        private readonly CompactionServiceInterface $compactionService,
    ) {
    }

    public function handleAfterTurnCommit(AfterTurnCommitHookContext $context): AfterTurnCommitHookContext
    {
        $runId = $context->runId;

        // Guard: fresh user turn — skip evaluation.  StartRun commits
        // signal a new user prompt cycle; auto-compaction should fire after
        // the turn completes (effectsCount=0) or via the pre-LLM guard,
        // never at the start of a new turn.
        if ($this->containsEventType($context, RunEventTypeEnum::RunStarted->value)) {
            return $context;
        }

        // Guard: compaction lifecycle events (context_compaction_started /
        // context_compacted / context_compaction_failed).  These commits
        // may carry continuation effects (AdvanceRun), so this guard must
        // run BEFORE the effectsCount check — otherwise lifecycle
        // commits with effects never set compactionResolved.
        if ($this->containsCompactionLifecycle($context)) {
            // Clear the in-process dedup flag — lifecycle commit signals
            // the async compaction has resolved.
            unset($this->inFlight[$runId]);

            return $context;
        }

        // Guard: skip commits that contain tool_execution_start.
        // The LlmStepResultHandler dispatches ExecuteToolCall effects
        // via a postCommit callback (not HandlerResult effects) when
        // the LLM step returns tool_calls.  This commit has
        // effectsCount=0 but the turn is mid-tool-cycle — tools have
        // not started executing yet.  If auto-compaction fires here,
        // it sets status=Compacting and the postCommit tool dispatch
        // is swallowed, same class of bug as ToolBatchCommitted.
        if ($this->containsEventType($context, RunEventTypeEnum::ToolExecutionStart->value)) {
            return $context;
        }

        // Guard: skip commits where there are unresolved tool calls.
        // ToolCallResultHandler emits partial-batch commits (single
        // tool_execution_end, no ToolBatchCommitted) when tool results
        // arrive one at a time.  These commits have effectsCount=0,
        // no ToolExecutionStart (emitted in a prior commit), and
        // no ToolBatchCommitted (batch not yet complete).  Auto-compaction
        // must NOT fire here — it dead-ends the turn mid-cycle.
        //
        // Check RunState::pendingToolCalls for false entries (unresolved).
        // The ToolExecutionStart guard catches the batch-start commit;
        // this guard catches the in-between partial commits.
        //
        // Fetch run state once here and reuse below for the in-flight
        // and provider-usage guards — avoids a double fetch that would
        // produce a stale-version mismatch in concurrent scenarios.
        $runState = $this->runStore->get($runId);
        if (null !== $runState) {
            foreach ($runState->pendingToolCalls as $completed) {
                if (true !== $completed) {
                    return $context;
                }
            }
        }

        // Guard: skip commits that contain tool_batch_committed.
        // ToolCallResultHandler schedules post-tool AdvanceRun as a
        // postCommit callback (not HandlerResult effects), so this
        // commit has effectsCount=0 but the turn will continue via
        // the imminent postCommit AdvanceRun.  If auto-compaction
        // fires here, it sets status=Compacting, the postCommit
        // AdvanceRun is swallowed by AdvanceRunHandler's Compacting
        // guard, and the final assistant answer is lost — the run
        // dead-ends at Completed after compaction (session 5 bug).
        //
        // Pre-LLM compaction guard in AdvanceRunHandler may still
        // trigger compaction with continueAfterCompaction=true before
        // the next LLM step if token pressure warrants; that is
        // semantically correct and preserves continuation.
        if ($this->containsEventType($context, RunEventTypeEnum::ToolBatchCommitted->value)) {
            return $context;
        }

        // Guard: skip commits that produced outbound effects (AdvanceRun,
        // CompactRun, ExecuteLlmStep, etc.).  These are intermediate
        // orchestration commits — effects will produce follow-up commits
        // that may themselves carry events or outcomes.  Evaluating
        // auto-compaction on intermediate commits causes duplicate
        // dispatches (hook + pre-LLM guard both fire, or the hook fires
        // on successive intermediate commits).  Only evaluate on stable
        // turn-level commits with no pending outbound work.
        if ($context->effectsCount > 0) {
            return $context;
        }

        // Guard: skip commits that contain AgentCommandQueued or
        // AgentCommandApplied.  These commits have effectsCount=0 but
        // may schedule AdvanceRun via a postCommit callback (the
        // ApplyCommandHandler schedules the follow-up AdvanceRun as a
        // postCommit, not via HandlerResult effects).  Dispatching
        // auto-compaction here would race the pending AdvanceRun and
        // dead-end the user turn (session 9: compaction won the race,
        // continueAfterCompaction=false, user command never resumed).
        //
        // The pre-LLM guard in AdvanceRunHandler may still compact
        // with continueAfterCompaction=true if token pressure warrants
        // before the next LLM step; that preserves the user turn.
        if ($this->containsUserCommandCommit($context)) {
            return $context;
        }

        // Resolve active model for per-provider/per-model override support.
        $activeModel = $this->modelResolver->getActiveModel($runId);
        $runtimeSettings = $this->compactionConfig->resolveRuntimeSettings($activeModel);

        if (!$runtimeSettings->autoEnabled) {
            return $context;
        }

        // Guard: skip when a compaction is already in flight.
        // activeStepId is set to e.g. 'compact-1234567890' by
        // CompactRunHandler before the lifecycle events are committed.
        if (null !== $runState && null !== $runState->activeStepId && str_starts_with($runState->activeStepId, 'compact-')) {
            return $context;
        }

        // Guard: in-process dedup — prevent double dispatch between the
        // hook call that dispatches CompactRun and the async worker
        // processing it (which sets activeStepId in a separate commit).
        if (isset($this->inFlight[$runId])) {
            return $context;
        }

        // Resolve context token count from latest provider measurement.
        // No provider measurement → no auto-compaction (the provider has
        // not yet measured this run's context).  The CompactionTokenEstimator
        // is NOT used as the trigger baseline — it undercounts real
        // provider context by omitting tool schemas, JSON envelope,
        // and provider-specific overhead.
        $effectiveTokens = $this->providerUsageResolver->getLatestEligibleInputTokens($runId);

        if (null === $effectiveTokens || $effectiveTokens <= $runtimeSettings->compactAfterTokens) {
            return $context;
        }

        // Dispatch auto compaction.
        //
        // ── Summary-only guard (session 14) ─────────────────────────
        // Before dispatching, check whether the compaction preparation
        // would summarize ONLY prior compact_summary messages with no
        // fresh non-summary conversation body.  This prevents the
        // pathological case where auto-compaction fires on successive
        // turns, each time compacting only the prior compact_summary
        // and producing near-zero token reduction — noise the user
        // sees as redundant "Conversation compacted" (session 14
        // seq149/150: messages_to_summarize=1,
        // prior_summary_present=true).
        //
        // Valid later re-compaction (session 14 seq230:
        // messages_compacted=26 including compact_summary + fresh
        // history) is NOT blocked — only summary-only partitions
        // are suppressed.  Structural preparation failures
        // (too_few_messages, below_keep_recent_tokens, no_boundary,
        // no_safe_boundary) are also silently skipped — the hook
        // should not emit visible failures for compile-time skips.
        // If RunState is absent (unlikely but defensive), skip — we
        // cannot inspect messages to prepare compaction boundaries.
        if (null === $runState) {
            return $context;
        }

        $prepareResult = $this->compactionService->prepare($runState->messages);

        if (!$prepareResult->isReady()) {
            return $context;
        }

        // Detect summary-only: priorSummaryPresent and ALL messages
        // in the summarize partition carry the compact_summary flag.
        if ($prepareResult->priorSummaryPresent && $this->isSummaryOnlyPartition($prepareResult->messagesToSummarize)) {
            return $context;
        }

        $this->dispatchAutoCompaction($runId);

        return $context;
    }

    /**
     * Returns true when every message in the partition carries the
     * compact_summary metadata flag — i.e. the partition contains
     * only prior compaction handoff summaries, not original
     * conversation content.
     *
     * @param list<\Ineersa\AgentCore\Domain\Message\AgentMessage> $messages
     */
    private function isSummaryOnlyPartition(array $messages): bool
    {
        foreach ($messages as $message) {
            if (true !== ($message->metadata['compact_summary'] ?? null)) {
                return false;
            }
        }

        return true;
    }

    private function dispatchAutoCompaction(string $runId): void
    {
        $this->inFlight[$runId] = true;

        $stepId = \sprintf('compact-%d', hrtime(true));

        try {
            $this->commandBus->dispatch(new CompactRun(
                runId: $runId,
                turnNo: 0,
                stepId: $stepId,
                attempt: 1,
                idempotencyKey: hash('sha256', \sprintf('%s|%s', $runId, $stepId)),
                trigger: 'auto',
            ));
        } catch (ExceptionInterface $exception) {
            // Clear the dedup flag on failure so a future hook call
            // can retry.
            unset($this->inFlight[$runId]);

            throw new \RuntimeException(\sprintf('Failed to dispatch auto-compaction CompactRun for run %s.', $runId), previous: $exception);
        }
    }

    private function containsCompactionLifecycle(AfterTurnCommitHookContext $context): bool
    {
        $lifecycleTypes = [
            RunEventTypeEnum::ContextCompactionStarted->value,
            RunEventTypeEnum::ContextCompacted->value,
            RunEventTypeEnum::ContextCompactionFailed->value,
        ];

        foreach ($context->events as $event) {
            if (\in_array($event->type, $lifecycleTypes, true)) {
                return true;
            }
        }

        return false;
    }

    private function containsEventType(AfterTurnCommitHookContext $context, string $type): bool
    {
        foreach ($context->events as $event) {
            if ($event->type === $type) {
                return true;
            }
        }

        return false;
    }

    /**
     * Returns true when the commit contains a user command event
     * (AgentCommandQueued or AgentCommandApplied).
     *
     * These commits have effectsCount=0 but may schedule AdvanceRun
     * via postCommit callbacks — dispensing auto-compaction here would
     * race the pending command processing.
     */
    private function containsUserCommandCommit(AfterTurnCommitHookContext $context): bool
    {
        $userCommandTypes = [
            RunEventTypeEnum::AgentCommandQueued->value,
            RunEventTypeEnum::AgentCommandApplied->value,
        ];

        foreach ($context->events as $event) {
            if (\in_array($event->type, $userCommandTypes, true)) {
                return true;
            }
        }

        return false;
    }
}
