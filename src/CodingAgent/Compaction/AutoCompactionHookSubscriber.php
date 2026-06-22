<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Compaction;

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
 *  - Provider context tokens ≤ threshold (or no provider measurement)
 *  - In-process dedup per run (prevents double dispatch within a single process
 *    between async compaction dispatch and lifecycle commit)
 */
final class AutoCompactionHookSubscriber implements HookSubscriberInterface
{
    /** @var array<string, true> Run IDs with an in-flight auto dispatch (in-process dedup) */
    private array $inFlight = [];

    /**
     * Run IDs where a compaction lifecycle commit has been observed.
     *
     * Prevents the hook from re-dispatching auto-compaction after the
     * pre-LLM guard path already handled it (or after a prior hook
     * dispatch completed).  Cleared when a new user turn starts
     * (run_started event in the commit).
     *
     * @var array<string, true>
     */
    private array $compactionResolved = [];

    public function __construct(
        private readonly RunStoreInterface $runStore,
        private readonly ProviderContextUsageResolver $providerUsageResolver,
        private readonly CompactionConfig $compactionConfig,
        private readonly ActiveModelResolverInterface $modelResolver,
        private readonly MessageBusInterface $commandBus,
    ) {
    }

    public function handleAfterTurnCommit(AfterTurnCommitHookContext $context): AfterTurnCommitHookContext
    {
        $runId = $context->runId;

        // Guard: fresh user turn — clear any prior compaction-resolved
        // flag and skip evaluation.  StartRun commits signal a new user
        // prompt cycle; auto-compaction should fire after the turn
        // completes (effectsCount=0) or via the pre-LLM guard, never at
        // the start of a new turn.
        if ($this->containsEventType($context, RunEventTypeEnum::RunStarted->value)) {
            unset($this->compactionResolved[$runId]);

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

            // Mark compaction as resolved for this logical user turn.
            // Prevents the hook from re-dispatching after the turn
            // continues (the continuation AdvanceRun may advance the
            // turnNo, and the subsequent stable commit would otherwise
            // re-trigger auto-compaction).
            $this->compactionResolved[$runId] = true;

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

        // Guard: when a prior auto-compaction lifecycle commit has been
        // observed (via containsCompactionLifecycle), skip further
        // auto-compaction evaluations for this logical user turn — the
        // pre-LLM guard or an earlier hook dispatch already handled it.
        // Cleared when run_started signals a new user prompt.
        if (isset($this->compactionResolved[$runId])) {
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
        $runState = $this->runStore->get($runId);
        if (null !== $runState && null !== $runState->activeStepId && str_starts_with($runState->activeStepId, 'compact-')) {
            return $context;
        }

        // Guard: in-process dedup — prevent double dispatch between the
        // hook call that dispatches CompactRun and the async worker
        // processing it (which sets activeStepId in a separate commit).
        if (isset($this->inFlight[$runId])) {
            return $context;
        }

        // Resolve effective context tokens = latest provider input/prompt
        // tokens + estimated delta for messages appended after that
        // measurement (assistant output, tool results, user messages).
        // No provider measurement → no auto-compaction.  The text-only
        // estimator is used ONLY for the post-measurement delta, never
        // as the whole trigger baseline.
        if (null === $runState) {
            return $context;
        }

        $effectiveTokens = $this->providerUsageResolver->getEffectiveContextTokens($runId, $runState->messages);

        if (null === $effectiveTokens || $effectiveTokens <= $runtimeSettings->compactAfterTokens) {
            return $context;
        }

        // Dispatch auto compaction.
        $this->dispatchAutoCompaction($runId);

        return $context;
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
}
