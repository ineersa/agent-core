<?php

declare(strict_types=1);

namespace Ineersa\Tui\Runtime;

use Ineersa\CodingAgent\Runtime\Contract\AgentSessionClient;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEvent;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventTypeEnum;
use Ineersa\Tui\Screen\ChatScreen;
use Psr\Log\LoggerInterface;

/**
 * Drains registered child run streams for catalog/HITL while live view is inactive.
 *
 * JsonlProcessAgentSessionClient buffers non-parent run ids until events($childRunId)
 * is called. Nested subagents launched inside a fork emit progress on the fork run
 * stream; this poller discovers them into SubagentLiveCatalog and surfaces HITL.
 *
 * Stored child backfill ({@see BackfillEventProviderInterface}) is owned by
 * {@see SubagentLiveChildViewPoller} for selected live-view transcript/question
 * rendering. This poller uses live {@see AgentSessionClient::events()} only so
 * one-shot backfill is not consumed before the user opens child live view.
 */
final class SubagentLiveBackgroundChildPoller
{
    private const float POLL_INTERVAL = 0.05;

    public function __construct(
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Drain child run streams for catalog/nested discovery only (no HITL callbacks).
     *
     * Must run while live view is active so nested scouts launched inside a fork
     * are registered before the user switches to their live view.
     */
    public function pollCatalogIngest(TuiSessionState $state, AgentSessionClient $client): void
    {
        $this->pollInternal(
            $state,
            $client,
            screen: null,
            onHumanInputRequested: null,
            onToolQuestionRequested: null,
            onToolTerminal: null,
            skipHitlForSelectedLiveChild: false,
            ingestOnly: true,
        );
    }

    public function poll(
        TuiSessionState $state,
        AgentSessionClient $client,
        ChatScreen $screen,
        ?callable $onHumanInputRequested = null,
        ?callable $onToolQuestionRequested = null,
        ?callable $onToolTerminal = null,
    ): void {
        $this->pollInternal(
            $state,
            $client,
            $screen,
            $onHumanInputRequested,
            $onToolQuestionRequested,
            $onToolTerminal,
            skipHitlForSelectedLiveChild: true,
            ingestOnly: false,
        );
    }

    private function pollInternal(
        TuiSessionState $state,
        AgentSessionClient $client,
        ?ChatScreen $screen,
        ?callable $onHumanInputRequested,
        ?callable $onToolQuestionRequested,
        ?callable $onToolTerminal,
        bool $skipHitlForSelectedLiveChild,
        bool $ingestOnly,
    ): void {
        $liveActive = $state->subagentLiveView->active;
        $selectedRunId = $liveActive && null !== $state->subagentLiveView->selected
            ? $state->subagentLiveView->selected->agentRunId
            : null;

        $now = microtime(true);
        if (($now - $state->subagentLiveBackgroundLastPoll) < self::POLL_INTERVAL) {
            return;
        }
        $state->subagentLiveBackgroundLastPoll = $now;

        foreach ($state->subagentLiveCatalog->all() as $child) {
            if (!$child->status->isActive()) {
                continue;
            }

            $runId = $child->agentRunId;
            if ('' === $runId) {
                continue;
            }

            $lastSeq = $state->subagentLiveBackgroundSeqByRunId[$runId] ?? 0;
            $events = $this->runtimeEvents($client, $runId);
            if ([] === $events) {
                continue;
            }

            $maxSeq = $lastSeq;
            foreach ($events as $event) {
                $seq = $event->seq;
                if (0 !== $seq && $seq <= $lastSeq) {
                    continue;
                }
                if (0 !== $seq) {
                    $maxSeq = max($maxSeq, $seq);
                }

                $this->ingestCatalogRelevantEvent($state, $event);

                if ($ingestOnly) {
                    continue;
                }

                $isSelectedLiveChild = $skipHitlForSelectedLiveChild
                    && null !== $selectedRunId
                    && $runId === $selectedRunId;

                if ($isSelectedLiveChild) {
                    continue;
                }

                if (null !== $onHumanInputRequested && RuntimeEventTypeEnum::HumanInputRequested->value === $event->type) {
                    $this->invokeCallback($onHumanInputRequested, $event, $runId, 'onHumanInputRequested');
                    if (null !== $screen) {
                        SubagentLiveAttention::markChildNeedsInputForRun($state, $screen, $runId);
                    }
                }

                if (null !== $onToolQuestionRequested && RuntimeEventTypeEnum::ToolQuestionRequested->value === $event->type) {
                    $this->invokeCallback($onToolQuestionRequested, $event, $runId, 'onToolQuestionRequested');
                    if (null !== $screen) {
                        SubagentLiveAttention::markChildNeedsInputForRun($state, $screen, $runId);
                    }
                }

                if (null !== $onToolTerminal && \in_array($event->type, [
                    RuntimeEventTypeEnum::ToolExecutionCompleted->value,
                    RuntimeEventTypeEnum::ToolExecutionFailed->value,
                    RuntimeEventTypeEnum::ToolExecutionCancelled->value,
                ], true)) {
                    $this->invokeCallback($onToolTerminal, $event, $runId, 'onToolTerminal');
                }
            }

            if ($maxSeq > $lastSeq) {
                $state->subagentLiveBackgroundSeqByRunId[$runId] = $maxSeq;
            }
        }
    }

    private function ingestCatalogRelevantEvent(TuiSessionState $state, RuntimeEvent $event): void
    {
        if (!str_contains($event->type, 'tool_execution')) {
            return;
        }

        $progress = $event->payload['subagent_progress'] ?? null;
        if (!\is_array($progress)) {
            return;
        }

        $state->subagentLiveCatalog->ingestNestedProgressFromChildRunEvent($event);
    }

    /**
     * @return list<RuntimeEvent>
     */
    private function runtimeEvents(AgentSessionClient $client, string $runId): array
    {
        $live = $client->events($runId);
        if ($live instanceof \Traversable) {
            return iterator_to_array($live, false);
        }

        return $live;
    }

    /**
     * @param callable(RuntimeEvent): void $callback
     */
    private function invokeCallback(callable $callback, RuntimeEvent $event, string $childRunId, string $callbackName): void
    {
        try {
            $callback($event);
        } catch (\Throwable $e) {
            $this->logger->warning('SubagentLiveBackgroundChildPoller event callback failed', [
                'component' => 'tui.subagent_live_background_poller',
                'event_type' => 'subagent_live_background_poller.callback_failed',
                'run_id' => $childRunId,
                'callback' => $callbackName,
                'runtime_event_type' => $event->type,
                'seq' => $event->seq,
                'exception_class' => $e::class,
                'exception_message' => $e->getMessage(),
            ]);
        }
    }
}
