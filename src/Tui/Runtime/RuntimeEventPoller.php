<?php

declare(strict_types=1);

namespace Ineersa\Tui\Runtime;

use Ineersa\CodingAgent\Runtime\Contract\AgentSessionClient;
use Ineersa\CodingAgent\Runtime\Contract\RuntimeExceptionBoundary;
use Ineersa\CodingAgent\Runtime\Contract\TurnTreeProviderInterface;
use Ineersa\CodingAgent\Runtime\Projection\TranscriptBlock;
use Ineersa\CodingAgent\Runtime\Projection\TranscriptBlockKindEnum;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEvent;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventTypeEnum;
use Psr\Log\LoggerInterface;

/**
 * Polls AgentSessionClient for new runtime events on each TUI tick.
 *
 * Runtime events update activity state, extract token usage, and are fed
 * through the transcript projector so the UI renders projected TranscriptBlock
 * DTOs. Events are NOT persisted here — canonical storage happens in AgentCore
 * (events.jsonl) and transient streaming deltas go through the controller's
 * LLM consumer stdout pipe.
 */
final class RuntimeEventPoller
{
    /** Polling interval in seconds (50ms). */
    private const float POLL_INTERVAL = 0.05;

    public function __construct(
        private readonly TuiRuntimeEventApplier $eventApplier,
        private readonly LoggerInterface $logger,
        private readonly RuntimeExceptionBoundary $boundary,
        private readonly TurnTreeProviderInterface $turnTreeProvider,
    ) {
    }

    /**
     * Poll for new runtime events and synchronize projected transcript blocks.
     *
     * @param ?callable(RuntimeEvent): void $onHumanInputRequested   Called when a
     *                                                               human_input.requested event is received; may be null if no handler
     * @param ?callable(RuntimeEvent): void $onToolQuestionRequested Called when a
     *                                                               tool_question.requested event is received; may be null if no handler
     * @param ?callable(RuntimeEvent): void $onToolTerminal          Called when a
     *                                                               tool_execution.completed, tool_execution.failed, or
     *                                                               tool_execution.cancelled event is received; may be null if no
     *                                                               handler. Used to close stale TUI question overlays when the
     *                                                               tool returns while a local tool question is still open.
     *
     * @return list<TranscriptBlock>|null Changed/new transcript blocks, or null if nothing new
     */
    public function poll(TuiSessionState $state, AgentSessionClient $client, ?callable $onHumanInputRequested = null, ?callable $onToolQuestionRequested = null, ?callable $onToolTerminal = null): ?array
    {
        if (null === $state->handle) {
            return null;
        }

        $now = microtime(true);
        if (($now - $state->lastPoll) < self::POLL_INTERVAL) {
            return null;
        }
        $state->lastPoll = $now;

        try {
            $events = $this->runtimeEvents($client, $state->handle->runId);
            if ([] === $events) {
                $state->runtimePollErrorCount = 0;
                $state->lastRuntimePollError = '';

                return null;
            }

            $state->runtimePollErrorCount = 0;
            $state->lastRuntimePollError = '';

            $hasNew = false;
            $processingRemoved = false;
            $hasRunLeafChanged = false;

            foreach ($events as $runtimeEvent) {
                $seq = $runtimeEvent->seq;

                // Seq 0 marks transient streaming events that do not
                // participate in persistent deduplication. Only stored
                // canonical events (seq > 0) advance the dedup cursor.
                if (0 !== $seq && $seq <= $state->lastSeq) {
                    continue;
                }

                if (0 !== $seq) {
                    $state->lastSeq = $seq;
                }
                $hasNew = true;

                $this->eventApplier->apply($state, $runtimeEvent);

                // ── Leaf change: rebuild transcript wholesale ──
                // The applier has reset the projector and returned early (no queued
                // follow-up dispatch, no callback handlers, no processing placeholder
                // removal). We now fetch active-path RuntimeEvents from the provider
                // and replay them through the projector to rebuild the transcript.
                if (RuntimeEventTypeEnum::RunLeafChanged->value === $runtimeEvent->type) {
                    $hasRunLeafChanged = true;

                    $leafTurnNo = (int) ($runtimeEvent->payload['turn_no'] ?? 0);

                    if ($leafTurnNo > 0 && null !== $state->handle) {
                        try {
                            $activeEvents = $this->turnTreeProvider->activePathRuntimeEvents(
                                $state->handle->runId,
                                $leafTurnNo,
                            );
                            $this->eventApplier->replayTranscriptOnly($activeEvents);
                            $state->transcript = $this->eventApplier->projectedBlocks();
                        } catch (\Throwable $e) {
                            $this->logger->warning('runtime_event_poller.leaf_changed_rebuild_failed', [
                                'run_id' => $state->handle->runId,
                                'leaf_turn_no' => $leafTurnNo,
                                'exception' => $e->getMessage(),
                            ]);
                            // Degrade gracefully: clear transcript so user sees blank
                            // rather than stale abandoned-branch content.
                            $state->transcript = [];
                        }
                    }

                    // Skip queued follow-up dispatch, callback handlers, and processing
                    // placeholder removal — all already handled by the applier's early
                    // return. The transcript has been wholesale-replaced above.
                    continue;
                }

                // Auto-dispatch a queued follow-up when cancellation completes.
                // The user may have typed a message during the Cancelling grace
                // window; it was queued in $state->queuedFollowUp instead of
                // being sent immediately (where it would be rejected).
                if (RuntimeEventTypeEnum::RunCancelled->value === $runtimeEvent->type
                    && null !== $state->queuedFollowUp
                    && null !== $state->handle) {
                    $queuedText = $state->queuedFollowUp;
                    $state->queuedFollowUp = null;

                    $client->send(
                        $state->handle->runId,
                        new \Ineersa\CodingAgent\Runtime\Contract\UserCommand(type: 'follow_up', text: $queuedText),
                    );
                    $state->activity = RunActivityStateEnum::Starting;
                }

                // Auto-dispatch a queued follow-up when compaction completes.
                // The user may have typed a message during the Compacting
                // window; it was queued in $state->queuedFollowUp instead of
                // being sent immediately (where it would race the compaction).
                //
                // GUARD: if activity is Cancelling, the user also pressed
                // Escape during compaction.  Do NOT dispatch the queued
                // follow-up on the compaction result — the RunCancelled
                // branch above handles dispatch after the cancellation
                // terminalizes.  Dispatching here would race the cancel
                // terminal and may start a new run before Cancelled is
                // visible in the UI.
                if ((RuntimeEventTypeEnum::CompactionCompleted->value === $runtimeEvent->type
                    || RuntimeEventTypeEnum::CompactionFailed->value === $runtimeEvent->type)
                    && null !== $state->queuedFollowUp
                    && null !== $state->handle
                    && RunActivityStateEnum::Cancelling !== $state->activity) {
                    $queuedText = $state->queuedFollowUp;
                    $state->queuedFollowUp = null;

                    $client->send(
                        $state->handle->runId,
                        new \Ineersa\CodingAgent\Runtime\Contract\UserCommand(type: 'follow_up', text: $queuedText),
                    );
                    $state->activity = RunActivityStateEnum::Starting;
                }

                // Notify handlers for specific event types (isolated: one bad overlay callback
                // must not drop later events in the same batch, e.g. run.cancelled).
                // Projection is handled by TuiRuntimeEventApplier::apply() above.
                if (null !== $onHumanInputRequested && RuntimeEventTypeEnum::HumanInputRequested->value === $runtimeEvent->type) {
                    $this->invokeEventCallback(
                        $onHumanInputRequested,
                        $runtimeEvent,
                        $state,
                        'onHumanInputRequested',
                    );
                }

                if (null !== $onToolQuestionRequested && RuntimeEventTypeEnum::ToolQuestionRequested->value === $runtimeEvent->type) {
                    $this->invokeEventCallback(
                        $onToolQuestionRequested,
                        $runtimeEvent,
                        $state,
                        'onToolQuestionRequested',
                    );
                }

                if (null !== $onToolTerminal && (
                    RuntimeEventTypeEnum::ToolExecutionCompleted->value === $runtimeEvent->type
                    || RuntimeEventTypeEnum::ToolExecutionFailed->value === $runtimeEvent->type
                    || RuntimeEventTypeEnum::ToolExecutionCancelled->value === $runtimeEvent->type
                )) {
                    $this->invokeEventCallback(
                        $onToolTerminal,
                        $runtimeEvent,
                        $state,
                        'onToolTerminal',
                    );
                }

                if (!$processingRemoved) {
                    self::removeProcessingPlaceholder($state);
                    $processingRemoved = true;
                }
            }

            if ($hasRunLeafChanged) {
                // Wholesale transcript replace: return all blocks (not just changed)
                // so the renderer rebuilds the entire transcript display.
                return $state->transcript;
            }

            if (!$hasNew) {
                return null;
            }

            return self::synchronizeProjectedBlocks($state, $this->eventApplier->projectedBlocks());
        } catch (\Throwable $e) {
            ++$state->runtimePollErrorCount;
            $state->lastRuntimePollError = $e->getMessage();

            $this->logger->warning('RuntimeEventPoller polling error', [
                'exception' => $e,
                'run_id' => $state->handle->runId,
                'consecutive_errors' => $state->runtimePollErrorCount,
            ]);

            if (!$this->isFatalPollingError($e) && $state->runtimePollErrorCount < 3) {
                // Show transient status on the first non-fatal error
                // so the user sees something instead of silence.
                // The poller will retry; if the issue persists, the
                // error block below kicks in at count=3.
                if (1 === $state->runtimePollErrorCount) {
                    $state->lastRuntimePollError = 'Polling issue ('.$e->getMessage().') — retrying...';
                }

                return null;
            }

            // Delegate capture=0 rethrow to boundary.
            // If we reach here, capture mode is enabled.
            $this->boundary->catch($e, 'runtime_event_poller.poll_failed', [
                'run_id' => $state->handle->runId,
                'consecutive_errors' => $state->runtimePollErrorCount,
            ]);

            // Capture mode: show the error and transition to Failed.
            $state->activity = RunActivityStateEnum::Failed;

            $block = new TranscriptBlock(
                id: \sprintf('runtime_poll_error_%s_%d', $state->handle->runId, $state->runtimePollErrorCount),
                kind: TranscriptBlockKindEnum::Error,
                runId: $state->handle->runId,
                seq: $state->lastSeq + 1,
                text: 'Runtime transport error: '.$e->getMessage(),
                meta: ['exception' => $e::class],
            );

            $state->transcript[] = $block;

            return [$block];
        }
    }

    /**
     * @param callable(RuntimeEvent): void $callback
     */
    private function invokeEventCallback(callable $callback, RuntimeEvent $runtimeEvent, TuiSessionState $state, string $callbackName): void
    {
        try {
            $callback($runtimeEvent);
        } catch (\Throwable $e) {
            $this->logger->warning('RuntimeEventPoller event callback failed', [
                'component' => 'tui.runtime_event_poller',
                'event_type' => 'runtime_event_poller.callback_failed',
                'run_id' => $state->handle->runId,
                'callback' => $callbackName,
                'runtime_event_type' => $runtimeEvent->type,
                'seq' => $runtimeEvent->seq,
                'exception_class' => $e::class,
                'exception_message' => $e->getMessage(),
            ]);
        }
    }

    private function isFatalPollingError(\Throwable $e): bool
    {
        $message = strtolower($e->getMessage());

        foreach (['process', 'pipe', 'transport', 'no such file', 'exited', 'closed', 'stdin', 'stdout'] as $needle) {
            if (str_contains($message, $needle)) {
                return true;
            }
        }

        return false;
    }

    /** @return list<RuntimeEvent> */
    private function runtimeEvents(AgentSessionClient $client, string $runId): array
    {
        $events = $client->events($runId);

        if ($events instanceof \Traversable) {
            /** @var list<RuntimeEvent> $list */
            $list = iterator_to_array($events, false);

            return $list;
        }

        return $events;
    }

    /**
     * @param list<TranscriptBlock> $projectedBlocks
     *
     * @return list<TranscriptBlock>
     */
    private static function synchronizeProjectedBlocks(TuiSessionState $state, array $projectedBlocks): array
    {
        $changed = [];

        foreach ($projectedBlocks as $block) {
            $idx = self::findBlockIndex($state->transcript, $block->id);

            if (null === $idx) {
                $state->transcript[] = $block;
                $changed[] = $block;

                continue;
            }

            if (self::blocksEqual($state->transcript[$idx], $block)) {
                continue;
            }

            $state->transcript[$idx] = $block;
            $changed[] = $block;
        }

        return $changed;
    }

    private static function blocksEqual(TranscriptBlock $left, TranscriptBlock $right): bool
    {
        return $left->id === $right->id
            && $left->kind === $right->kind
            && $left->runId === $right->runId
            && $left->seq === $right->seq
            && $left->text === $right->text
            && $left->meta === $right->meta
            && $left->streaming === $right->streaming
            && $left->collapsed === $right->collapsed;
    }

    /** @param list<TranscriptBlock> $blocks */
    private static function findBlockIndex(array $blocks, string $id): ?int
    {
        foreach ($blocks as $idx => $block) {
            if ($block->id === $id) {
                return $idx;
            }
        }

        return null;
    }

    private static function removeProcessingPlaceholder(TuiSessionState $state): void
    {
        $lastIdx = \count($state->transcript) - 1;
        if ($lastIdx < 0) {
            return;
        }

        $last = $state->transcript[$lastIdx];
        if (TranscriptBlockKindEnum::System === $last->kind && str_contains($last->text, 'Processing...')) {
            array_pop($state->transcript);
        }
    }
}
