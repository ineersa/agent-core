<?php

declare(strict_types=1);

namespace Ineersa\Tui\Runtime;

use Ineersa\CodingAgent\Runtime\Contract\AgentSessionClient;
use Ineersa\CodingAgent\Runtime\Contract\RuntimeExceptionBoundary;
use Ineersa\CodingAgent\Runtime\Contract\RuntimeTransportException;
use Ineersa\CodingAgent\Runtime\Contract\SessionTranscriptProviderInterface;
use Ineersa\CodingAgent\Runtime\Projection\TranscriptBlock;
use Ineersa\CodingAgent\Runtime\Projection\TranscriptBlockKindEnum;
use Ineersa\CodingAgent\Runtime\Projection\TranscriptChangeSet;
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

    /**
     * Events already consumed from the process pipe but not yet successfully
     * applied. Retain only a failed suffix so a projector failure cannot lose
     * a canonical event before its sequence cursor advances.
     *
     * @var list<RuntimeEvent>
     */
    private array $pendingEvents = [];

    public function __construct(
        private readonly TuiRuntimeEventApplier $eventApplier,
        private readonly LoggerInterface $logger,
        private readonly RuntimeExceptionBoundary $boundary,
        private readonly SessionTranscriptProviderInterface $sessionTranscriptProvider,
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
     * @return TranscriptChangeSet|null Canonical transcript delta for ChatScreen, or null if nothing new
     */
    public function poll(TuiSessionState $state, AgentSessionClient $client, ?callable $onHumanInputRequested = null, ?callable $onToolQuestionRequested = null, ?callable $onToolTerminal = null): ?TranscriptChangeSet
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
            if ([] !== $this->pendingEvents && $this->pendingEvents[0]->runId !== $state->handle->runId) {
                $this->pendingEvents = [];
            }

            $retryingPendingEvents = [] !== $this->pendingEvents;
            $events = $retryingPendingEvents
                ? $this->pendingEvents
                : RuntimeEventCallbacks::eventList($client, $state->handle->runId, $state->lastSeq);
            if ([] === $events) {
                $state->runtimePollErrorCount = 0;
                $state->lastRuntimePollError = '';

                return null;
            }

            // A fresh pipe read clears an old error episode. Retained suffixes
            // deliberately do not: a deterministic apply failure must reach the
            // existing three-strike escape rather than retry forever.
            if (!$retryingPendingEvents) {
                $state->runtimePollErrorCount = 0;
                $state->lastRuntimePollError = '';
            }

            $hasNew = false;
            $processingRemoved = false;
            $hasRunHistoryPositionChanged = false;
            $removedProcessing = false;

            $callbacks = new RuntimeEventCallbacks(
                $this->logger,
                'RuntimeEventPoller event callback failed',
                'tui.runtime_event_poller',
                'runtime_event_poller.callback_failed',
                $onHumanInputRequested,
                $onToolQuestionRequested,
                $onToolTerminal,
            );

            foreach ($events as $index => $runtimeEvent) {
                $seq = $runtimeEvent->seq;

                // Seq 0 marks transient streaming events that do not
                // participate in persistent deduplication. Only stored
                // canonical events (seq > 0) advance the dedup cursor.
                if (0 !== $seq && $seq <= $state->lastSeq) {
                    continue;
                }

                $hasNew = true;

                try {
                    $this->eventApplier->apply($state, $runtimeEvent);

                    // ── History position change: rebuild transcript wholesale ──
                    // The applier resets live projector state; projected blocks come from
                    // SessionTranscriptProvider (isolated projector), not TUI local replay.
                    if (RuntimeEventTypeEnum::RunHistoryPositionChanged->value === $runtimeEvent->type) {
                        // position_turn_no is retained tip; 0 means before first turn (valid).
                        $hasPositionKey = \array_key_exists('position_turn_no', $runtimeEvent->payload);
                        $positionTurnNo = (int) ($runtimeEvent->payload['position_turn_no'] ?? -1);
                        $editorPromptText = \is_string($runtimeEvent->payload['editor_prompt_text'] ?? null)
                            ? $runtimeEvent->payload['editor_prompt_text']
                            : '';
                        // Always treat history position change as wholesale replace so the mounted path
                        // receives an explicit full snapshot (including empty after failure).
                        $hasRunHistoryPositionChanged = true;

                        if ($hasPositionKey && $positionTurnNo >= 0 && null !== $state->handle) {
                            try {
                                if ($positionTurnNo > 0) {
                                    $snapshot = $this->sessionTranscriptProvider->transcriptAtPosition(
                                        $state->handle->runId,
                                        $positionTurnNo,
                                    );
                                    // Route through applyTranscriptChangeSet so local UI blocks
                                    // unknown to the isolated history projector are preserved and
                                    // projector-evicted content cannot return via wholesale replace.
                                    $state->applyTranscriptChangeSet(
                                        TranscriptChangeSet::full($snapshot->transcriptBlocks),
                                    );
                                } else {
                                    // Before first turn: empty conversation transcript.
                                    $state->applyTranscriptChangeSet(TranscriptChangeSet::full([]));
                                }
                            } catch (\Throwable $e) {
                                $this->logger->warning('runtime_event_poller.history_position_changed_rebuild_failed', [
                                    'run_id' => $state->handle->runId,
                                    'position_turn_no' => $positionTurnNo,
                                    'exception' => $e->getMessage(),
                                ]);
                                // Intentional degradation: clear transcript rather than show stale
                                // discarded-tail content when projection fails.
                                $state->applyTranscriptChangeSet(TranscriptChangeSet::full([]));
                            }

                            if ('' !== $editorPromptText) {
                                $state->pendingEditorPromptText = $editorPromptText;
                            }
                        } else {
                            // Malformed RunHistoryPositionChanged: missing position_turn_no, or no handle.
                            $this->logger->warning('runtime_event_poller.history_position_changed_malformed', [
                                'run_id' => null !== $state->handle ? $state->handle->runId : 'unknown',
                                'position_turn_no' => $positionTurnNo,
                            ]);
                            $state->applyTranscriptChangeSet(TranscriptChangeSet::full([]));
                        }

                        // Skip queued follow-up dispatch, callback handlers, and processing
                        // placeholder removal — all already handled by the applier's early
                        // return. The transcript has been wholesale-replaced above.
                        if (0 !== $seq) {
                            $state->lastSeq = $seq;
                        }
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
                    $callbacks->dispatch($runtimeEvent, $state->handle->runId);

                    if (!$processingRemoved) {
                        $beforeCount = \count($state->transcript);
                        $state->removeTrailingProcessingPlaceholder();
                        if (\count($state->transcript) < $beforeCount) {
                            $removedProcessing = true;
                        }
                        $processingRemoved = true;
                    }

                    // Advance only after projection, callbacks, and local state changes
                    // have all succeeded. A failed event and its suffix stay retryable.
                    if (0 !== $seq) {
                        $state->lastSeq = $seq;
                    }
                } catch (\Throwable $e) {
                    $this->pendingEvents = \array_slice($events, $index);

                    throw $e;
                }
            }
            $this->pendingEvents = [];

            if ($hasRunHistoryPositionChanged) {
                // Wholesale position replace already applied; drain projector dirty set for any
                // post-position events in the same batch, then return an explicit full snapshot.
                $postPosition = $this->eventApplier->drainProjectedChanges();
                if (!$postPosition->isEmpty()) {
                    $state->applyTranscriptChangeSet($postPosition);
                }

                return TranscriptChangeSet::full($state->transcript);
            }

            if (!$hasNew) {
                return null;
            }

            $changes = $this->eventApplier->drainProjectedChanges();
            if (!$changes->isEmpty()) {
                $state->applyTranscriptChangeSet($changes);

                return $changes;
            }

            // Local Processing… placeholder removal is not a projector dirty event, but the
            // mounted transcript still needs an explicit full snapshot to drop it.
            if ($removedProcessing) {
                return TranscriptChangeSet::full($state->transcript);
            }

            return null;
        } catch (\Throwable $e) {
            ++$state->runtimePollErrorCount;
            $state->lastRuntimePollError = $e->getMessage();

            $this->logger->warning('RuntimeEventPoller polling error', [
                'exception' => $e,
                'run_id' => $state->handle->runId,
                'consecutive_errors' => $state->runtimePollErrorCount,
            ]);

            // Only typed transport failures are immediately fatal. Any other
            // exception (domain, EventStore, mapper, malformed events) stays
            // retryable until the consecutive-error ceiling is reached.
            if (!$e instanceof RuntimeTransportException && $state->runtimePollErrorCount < 3) {
                // Show transient status on the first non-fatal error
                // so the user sees something instead of silence.
                // The poller will retry; if the issue persists, the
                // error block below kicks in at count=3.
                if (1 === $state->runtimePollErrorCount) {
                    $state->lastRuntimePollError = 'Polling issue ('.$e->getMessage().') — retrying...';
                }

                return null;
            }

            // The retained suffix has reached its terminal handling boundary.
            // Release it so subsequent polls can drain fresh controller frames.
            $this->pendingEvents = [];

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

            $state->appendTranscriptBlock($block);

            return TranscriptChangeSet::incremental([$block]);
        }
    }
}
