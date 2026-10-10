<?php

declare(strict_types=1);

namespace Ineersa\Tui\Runtime;

use Ineersa\CodingAgent\Runtime\Contract\AgentSessionClient;
use Ineersa\CodingAgent\Runtime\Contract\RunHandle;
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
    /** Polling interval in seconds (15ms). */
    private const float POLL_INTERVAL = 0.015;

    /**
     * Events already consumed from the process pipe but not yet successfully
     * applied. Retain only a failed suffix so a projector failure cannot lose
     * a canonical event before its sequence cursor advances.
     *
     * @var list<RuntimeEvent>
     */
    private array $pendingEvents = [];

    /** A settled deferred submission remains retryable even after its event is consumed. */
    private bool $deferredInputReady = false;

    /**
     * Scalar boundaries from events that finished apply in the latest poll.
     * Cleared at the start of each poll and by {@see consumeAppliedMemoryBoundaryObservation()}.
     */
    private AppliedMemoryBoundaryObservation $appliedMemoryBoundaries;

    public function __construct(
        private readonly TuiRuntimeEventApplier $eventApplier,
        private readonly LoggerInterface $logger,
        private readonly RuntimeExceptionBoundary $boundary,
        private readonly SessionTranscriptProviderInterface $sessionTranscriptProvider,
    ) {
        $this->appliedMemoryBoundaries = new AppliedMemoryBoundaryObservation();
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
    public function poll(TuiSessionState $state, AgentSessionClient $client, ?callable $onHumanInputRequested = null, ?callable $onToolQuestionRequested = null, ?callable $onToolTerminal = null, ?callable $onBootstrapMounted = null, ?callable $onSessionRestoring = null): ?TranscriptChangeSet
    {
        if (null === $state->handle) {
            return null;
        }

        $now = microtime(true);
        if (($now - $state->lastPoll) < self::POLL_INTERVAL) {
            return null;
        }
        $state->lastPoll = $now;

        $this->appliedMemoryBoundaries = new AppliedMemoryBoundaryObservation();

        try {
            if (!$state->sessionReady) {
                if (null !== $state->bootstrapError) {
                    $this->eventApplier->releaseBootstrap();
                    $this->pendingEvents = [];

                    return null;
                }
                if (0.0 === $state->bootstrapStartedAt) {
                    $state->bootstrapStartedAt = $now;
                }
                if ($now - $state->bootstrapStartedAt > 60) {
                    throw new RuntimeTransportException('Session bootstrap timed out. Reload to attach again.');
                }
            }
            if ([] !== $this->pendingEvents && $this->pendingEvents[0]->runId !== $state->handle->runId) {
                $this->pendingEvents = [];
            }

            $retryingPendingEvents = [] !== $this->pendingEvents;
            $events = $retryingPendingEvents
                ? $this->pendingEvents
                : RuntimeEventCallbacks::eventList($client, $state->handle->runId, $state->lastSeq);
            if ([] === $events) {
                $retryingDeferredInput = $this->deferredInputReady;
                $this->dispatchDeferredInput($state, $client);
                $state->runtimePollErrorCount = 0;
                $state->lastRuntimePollError = '';

                // A send can fail after readiness consumed the suffix. Finish
                // its projection on retry even when no new pipe event arrives.
                return $retryingDeferredInput ? $this->applyProjectedChanges($state) : null;
            }

            // A fresh pipe read clears an old error episode. Retained suffixes
            // deliberately do not: a deterministic apply failure must reach the
            // existing three-strike escape rather than retry forever. An unsent
            // settled intent must reach that same boundary despite fresh frames.
            if (!$retryingPendingEvents && !$this->deferredInputReady) {
                $state->runtimePollErrorCount = 0;
                $state->lastRuntimePollError = '';
            }

            $hasNew = false;
            $processingRemoved = false;
            $hasRunHistoryPositionChanged = false;
            $removedProcessing = false;
            $boundaryActivityBefore = null;
            $boundaryLastSeqBefore = $state->lastSeq;
            $boundaryBecameTerminal = false;
            $boundaryCompactionSettled = false;
            $boundaryTerminalActivity = null;

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
                if (RuntimeEventTypeEnum::SessionRestoring->value === $runtimeEvent->type) {
                    $requestId = $runtimeEvent->payload['command_id'] ?? null;
                    if ($runtimeEvent->runId === $state->handle->runId
                        && \array_key_exists('previous_command_id', $runtimeEvent->payload)
                        && $runtimeEvent->payload['previous_command_id'] === $state->handle->bootstrapRequestId
                        && \is_string($requestId) && '' !== $requestId
                        && $requestId !== $state->handle->bootstrapRequestId) {
                        $this->eventApplier->releaseBootstrap();
                        $this->pendingEvents = [];
                        $state->handle = new RunHandle($runtimeEvent->runId, 'bootstrapping', $requestId);
                        $state->sessionReady = false;
                        $state->bootstrapMounted = false;
                        $state->bootstrapError = null;
                        $state->bootstrapStartedAt = $now;
                        $this->deferredInputReady = false;
                        if (null !== $onSessionRestoring) {
                            $onSessionRestoring();
                        }
                    }
                    continue;
                }
                if (!$state->sessionReady && (RuntimeEventTypeEnum::ProtocolError->value === $runtimeEvent->type
                    || (RuntimeEventTypeEnum::CommandRejected->value === $runtimeEvent->type
                        && ($runtimeEvent->payload['command_id'] ?? null) === $state->handle->bootstrapRequestId))) {
                    throw new RuntimeTransportException('Session attachment was refused. Reload to attach again.');
                }

                try {
                    $wasReady = $state->sessionReady;
                    if ($this->eventApplier->applyBootstrap($state, $client, $runtimeEvent, $onBootstrapMounted)) {
                        if (!$wasReady && $state->sessionReady) {
                            // The suffix may supersede the snapshot outcome. Reconcile
                            // only after mount, exact ACK and complete catch-up.
                            if (RunActivityStateEnum::Failed === $state->activity) {
                                $this->eventApplier->restoreDeferredInput($state);
                                $this->deferredInputReady = false;
                            } else {
                                $this->deferredInputReady = $this->deferredInputReady
                                    || \in_array($state->activity, [RunActivityStateEnum::Idle, RunActivityStateEnum::Completed, RunActivityStateEnum::Cancelled], true);
                            }
                        }
                        continue;
                    }
                } catch (\Throwable $exception) {
                    throw new RuntimeTransportException('Session bootstrap failed validation. Reload to attach again.', 0, $exception);
                }
                // No optimistic streaming or canonical suffix may mutate the old
                // view before the sealed snapshot is mounted. After mount, only
                // durable catch-up is accepted until session.ready.
                if (!$state->sessionReady && (!$state->bootstrapMounted || 0 === $seq)) {
                    continue;
                }

                // Seq 0 marks transient streaming events that do not
                // participate in persistent deduplication. Only stored
                // canonical events (seq > 0) advance the dedup cursor.
                if (0 !== $seq && $seq <= $state->lastSeq) {
                    continue;
                }

                $hasNew = true;

                try {
                    $activityBeforeApply = $state->activity;
                    $compactingBeforeApply = $state->isCompacting;
                    $this->eventApplier->apply($state, $runtimeEvent);
                    $activityAfterApply = $state->activity;
                    $compactingAfterApply = $state->isCompacting;

                    if (null === $boundaryActivityBefore) {
                        $boundaryActivityBefore = $activityBeforeApply->value;
                    }
                    // Observe real apply transitions only. Already-applied retries
                    // skip before apply via lastSeq; ignored state-machine events
                    // leave activity/isCompacting unchanged and must not mark.
                    // CompactionCompleted/Failed leave Compacting for Completed on the
                    // same event; that is settlement, not a separate terminal sample.
                    if (!$activityBeforeApply->isTerminal()
                        && $activityAfterApply->isTerminal()
                        && !($compactingBeforeApply && !$compactingAfterApply)
                    ) {
                        $boundaryBecameTerminal = true;
                        $boundaryTerminalActivity = $activityAfterApply->value;
                    }
                    if ($compactingBeforeApply && !$compactingAfterApply) {
                        $boundaryCompactionSettled = true;
                    }

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
                                    // Full replacement restores authoritative retained history.
                                    // Hydrate the live projector from the same snapshot so later
                                    // compaction.completed can find prior retention markers.
                                    $state->applyTranscriptChangeSet(
                                        TranscriptChangeSet::full($snapshot->transcriptBlocks),
                                    );
                                    $this->eventApplier->hydrateProjectedTranscript($snapshot->transcriptBlocks);
                                } else {
                                    // Before first turn: empty conversation transcript.
                                    $state->applyTranscriptChangeSet(TranscriptChangeSet::full([]));
                                    $this->eventApplier->hydrateProjectedTranscript([]);
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
                                $this->eventApplier->hydrateProjectedTranscript([]);
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
                            $this->eventApplier->hydrateProjectedTranscript([]);
                        }

                        // Skip queued follow-up dispatch, callback handlers, and processing
                        // placeholder removal — all already handled by the applier's early
                        // return. The transcript has been wholesale-replaced above.
                        if (0 !== $seq) {
                            $state->lastSeq = $seq;
                        }
                        continue;
                    }

                    // Release deferred input after cancellation, compaction
                    // settlement, or rejection of the pending compact request.
                    //
                    // GUARD: if activity is Cancelling, the user also pressed
                    // Escape during compaction.  Do NOT dispatch the queued
                    // follow-up on the compaction result — the RunCancelled
                    // event handles dispatch after the cancellation
                    // terminalizes.  Dispatching here would race the cancel
                    // terminal and may start a new run before Cancelled is
                    // visible in the UI.
                    // Keep intent in the queue until each send succeeds. The
                    // applier may clear isCompacting before projection throws,
                    // so retries must not depend on that boolean transition.
                    // Failure events move old input to editor restoration; a
                    // historical Failed activity must not block fresh input
                    // from a later request, including rejection before start.
                    if ((RuntimeEventTypeEnum::RunCancelled->value === $runtimeEvent->type
                        || RuntimeEventTypeEnum::CompactionCompleted->value === $runtimeEvent->type
                        || RuntimeEventTypeEnum::CompactionFailed->value === $runtimeEvent->type
                        || (RuntimeEventTypeEnum::CommandRejected->value === $runtimeEvent->type
                            && 'compact' === ($runtimeEvent->payload['commandType'] ?? null)))
                        && !$state->isCompacting
                        && !\in_array($state->activity, [RunActivityStateEnum::Cancelling, RunActivityStateEnum::Compacting], true)) {
                        $this->deferredInputReady = true;
                    }

                    $this->dispatchDeferredInput($state, $client);

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
                    // Apply may have already mutated activity/compaction; keep the
                    // coalesced marks for this tick even when the suffix retries.
                    $this->rememberAppliedMemoryBoundaries(
                        $boundaryBecameTerminal,
                        $boundaryCompactionSettled,
                        $boundaryActivityBefore,
                        $boundaryTerminalActivity,
                        $boundaryLastSeqBefore,
                        max($boundaryLastSeqBefore, $state->lastSeq),
                    );
                    $this->pendingEvents = \array_slice($events, $index);

                    throw $e;
                }
            }
            $this->rememberAppliedMemoryBoundaries(
                $boundaryBecameTerminal,
                $boundaryCompactionSettled,
                $boundaryActivityBefore,
                $boundaryTerminalActivity,
                $boundaryLastSeqBefore,
                $state->lastSeq,
            );
            $this->pendingEvents = [];

            $this->dispatchDeferredInput($state, $client);

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

            $changes = $this->applyProjectedChanges($state);
            if (null !== $changes) {
                return $changes;
            }

            // Local Processing… placeholder removal is not a projector dirty event, but the
            // mounted transcript still needs an explicit full snapshot to drop it.
            if ($removedProcessing) {
                return TranscriptChangeSet::full($state->transcript);
            }

            return null;
        } catch (\Throwable $e) {
            if (!$state->sessionReady) {
                $this->eventApplier->releaseBootstrap();
                $state->bootstrapError = 'Not attached. Reload to restore this session.';
                $state->bootstrapMounted = false;
                $this->pendingEvents = [];
                try {
                    $client->cancelBootstrap($state->handle->runId);
                } catch (\Throwable $cleanupFailure) {
                    $this->logger->warning('tui.bootstrap.cancel_failed', ['run_id' => $state->handle->runId,
                        'session_id' => $state->sessionId, 'component' => 'tui', 'event_type' => 'tui.bootstrap.cancel_failed',
                        'exception_class' => $cleanupFailure::class]);
                }
                if (!$e instanceof RuntimeTransportException) {
                    $e = new RuntimeTransportException('Session bootstrap could not complete.', 0, $e);
                }
            }
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
            $this->eventApplier->restoreDeferredInput($state);
            $this->deferredInputReady = false;
            $projectedChanges = $this->applyProjectedChanges($state);

            $block = new TranscriptBlock(
                id: \sprintf('runtime_poll_error_%s_%d', $state->handle->runId, $state->runtimePollErrorCount),
                kind: TranscriptBlockKindEnum::Error,
                runId: $state->handle->runId,
                seq: $state->lastSeq + 1,
                text: 'Runtime transport error: '.$e->getMessage(),
                meta: ['exception' => $e::class],
            );

            $state->appendTranscriptBlock($block);

            return null === $projectedChanges ? TranscriptChangeSet::incremental([$block]) : TranscriptChangeSet::full($state->transcript);
        }
    }

    /**
     * Take and clear scalar memory boundaries from the latest successful applies.
     */
    public function consumeAppliedMemoryBoundaryObservation(): AppliedMemoryBoundaryObservation
    {
        $observation = $this->appliedMemoryBoundaries;
        $this->appliedMemoryBoundaries = new AppliedMemoryBoundaryObservation();

        return $observation;
    }

    private function applyProjectedChanges(TuiSessionState $state): ?TranscriptChangeSet
    {
        $changes = $this->eventApplier->drainProjectedChanges();
        if ($changes->isEmpty()) {
            return null;
        }
        $state->applyTranscriptChangeSet($changes);

        // Retention-floor advances can drop session-local UI blocks that
        // never entered the projector. Return an authoritative snapshot so
        // the mounted transcript matches session state.
        return null !== $changes->retentionFloorBlockId ? TranscriptChangeSet::full($state->transcript) : $changes;
    }

    private function dispatchDeferredInput(TuiSessionState $state, AgentSessionClient $client): void
    {
        if (!$this->deferredInputReady || !$state->sessionReady || null === $state->handle
            || $state->isCompacting || \in_array($state->activity, [RunActivityStateEnum::Cancelling, RunActivityStateEnum::Compacting], true)) {
            return;
        }
        while ([] !== $state->queuedFollowUps) {
            $client->send($state->handle->runId,
                new \Ineersa\CodingAgent\Runtime\Contract\UserCommand(type: 'follow_up', text: $state->queuedFollowUps[0]));
            array_shift($state->queuedFollowUps);
            $state->activity = RunActivityStateEnum::Starting;
        }
        $this->deferredInputReady = false;
    }

    private function rememberAppliedMemoryBoundaries(
        bool $becameTerminal,
        bool $compactionSettled,
        ?string $activityBefore,
        ?string $terminalActivity,
        int $lastSeqBefore,
        int $lastSeqAfter,
    ): void {
        if (!$becameTerminal && !$compactionSettled) {
            return;
        }

        $this->appliedMemoryBoundaries = new AppliedMemoryBoundaryObservation(
            activityBecameTerminal: $becameTerminal,
            compactionSettled: $compactionSettled,
            activityBefore: $activityBefore,
            terminalActivity: $terminalActivity,
            lastSeqBefore: $lastSeqBefore,
            lastSeqAfter: $lastSeqAfter,
        );
    }
}
