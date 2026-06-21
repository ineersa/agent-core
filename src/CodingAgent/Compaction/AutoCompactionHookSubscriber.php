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
 * After-turn hook that triggers auto-compaction when the estimated context
 * tokens exceed the compact_after_tokens threshold.
 *
 * Registered as a HookSubscriberInterface (auto-tagged agent_core.hook_subscriber).
 * Runs synchronously inside RunCommit::commit() so the dispatch is a fire-and-
 * forget send to agent.command.bus — it never blocks the commit.
 *
 * Guards:
 *  - Auto disabled via compaction.auto_enabled (per-provider/per-model overrides)
 *  - In-flight compaction (activeStepId starts with compact-)
 *  - Commit contains compaction lifecycle events (avoids loops)
 *  - Token estimate ≤ threshold
 *  - In-process dedup per run (prevents double dispatch within a single process
 *    between async compaction dispatch and lifecycle commit)
 */
final class AutoCompactionHookSubscriber implements HookSubscriberInterface
{
    /** @var array<string, true> Run IDs with an in-flight auto dispatch (in-process dedup) */
    private array $inFlight = [];

    public function __construct(
        private readonly RunStoreInterface $runStore,
        private readonly CompactionTokenEstimator $tokenEstimator,
        private readonly CompactionConfig $compactionConfig,
        private readonly ActiveModelResolverInterface $modelResolver,
        private readonly MessageBusInterface $commandBus,
    ) {
    }

    public function handleAfterTurnCommit(AfterTurnCommitHookContext $context): AfterTurnCommitHookContext
    {
        $runId = $context->runId;

        // Resolve active model for per-provider/per-model override support.
        $activeModel = $this->modelResolver->getActiveModel($runId);
        $runtimeSettings = $this->compactionConfig->resolveRuntimeSettings($activeModel);

        if (!$runtimeSettings->autoEnabled) {
            return $context;
        }

        // Guard: skip when the current commit contains compaction lifecycle
        // events (context_compaction_started / context_compacted /
        // context_compaction_failed).  Without this, compaction lifecycle
        // commits would re-enter the hook and trigger repeated dispatches.
        if ($this->containsCompactionLifecycle($context)) {
            // Clear the in-process dedup flag — lifecycle commit signals
            // the async compaction has resolved.
            unset($this->inFlight[$runId]);

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

        // Estimate current context tokens.
        if (null === $runState) {
            return $context;
        }

        $estimatedTokens = $this->tokenEstimator->estimateTokens($runState->messages);

        if ($estimatedTokens <= $runtimeSettings->compactAfterTokens) {
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
}
