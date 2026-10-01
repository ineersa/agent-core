<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Session\Replay;

use Ineersa\AgentCore\Application\Handler\RunLockManager;
use Ineersa\AgentCore\Application\Replay\RunStateIncrementalApplication;
use Ineersa\AgentCore\Application\Replay\RunStateReducer;
use Ineersa\AgentCore\Contract\ActiveRunContextInterface;
use Ineersa\AgentCore\Contract\EventStoreInterface;
use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Domain\Event\RunEventTypeEnum;
use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\AgentCore\Infrastructure\RunLogContext;
use Ineersa\CodingAgent\Logging\ProcessMemorySnapshotLogger;
use Ineersa\CodingAgent\Runtime\Contract\SessionResumeProjectionDTO;
use Ineersa\CodingAgent\Runtime\Contract\SessionTranscriptSnapshotDTO;
use Ineersa\CodingAgent\Runtime\Contract\SubagentProgress\SubagentProgressSnapshotInterface;
use Ineersa\CodingAgent\Runtime\Contract\TranscriptProjectorInterface;
use Ineersa\CodingAgent\Runtime\Projection\TranscriptBlock;
use Ineersa\CodingAgent\Runtime\Protocol\ResumeActivityReducer;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEvent;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventMapper;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventTypeEnum;
use Ineersa\CodingAgent\Session\History\HistoryDTO;
use Ineersa\CodingAgent\Session\History\HistoryProjectionSnapshot;
use Ineersa\CodingAgent\Session\History\HistoryProjectionStoreInterface;
use Ineersa\CodingAgent\Session\History\HistoryProjector;
use Ineersa\CodingAgent\Session\RunState\RunStateStoreInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;

/**
 * Central cold startup/recovery reconstruction.
 *
 * One ordered archive traversal publishes shared history and optionally shared
 * RunState after successful local construction. Ordinary get/applyCommitted
 * paths never call this.
 */
final readonly class SessionColdReconstructionService
{
    public function __construct(
        private EventStoreInterface $eventStore,
        private HistoryProjector $historyProjector,
        private HistoryProjectionStoreInterface $historyProjectionStore,
        private RunStateReducer $runStateReducer,
        private RuntimeEventMapper $eventMapper,
        private TranscriptProjectorInterface $transcriptProjector,
        private LoggerInterface $logger,
        private RunLockManager $runLockManager,
        private DenormalizerInterface $denormalizer,
        private ?ActiveRunContextInterface $activeRunContext = null,
        private ?RunStateStoreInterface $runStateStore = null,
    ) {
    }

    public function reconstruct(
        string $runId,
        ?int $positionTurnNo = null,
        ?RunState $seedState = null,
        bool $publishSharedState = false,
        bool $publishHistory = true,
        ?int $knownMaxSeq = null,
    ): SessionColdReconstructionResult {
        $endSeq = $knownMaxSeq ?? \PHP_INT_MAX;

        return $this->reconstructFromEventSource(
            $runId,
            fn (): iterable => $this->eventStore->rangeFor($runId, 1, $endSeq),
            $positionTurnNo,
            $seedState,
            $publishSharedState,
            $publishHistory,
            $knownMaxSeq,
        );
    }

    /**
     * @param callable():iterable<RunEvent> $eventSource
     */
    private function reconstructFromEventSource(
        string $runId,
        callable $eventSource,
        ?int $positionTurnNo,
        ?RunState $seedState,
        bool $publishSharedState,
        bool $publishHistory,
        ?int $expectedMaxSeq,
    ): SessionColdReconstructionResult {
        RunLogContext::enter(['run_id' => $runId, 'component' => 'cold_reconstruction']);

        try {
            $planner = new HistoryReplayStreamPlanner($this->historyProjector);
            $seed = $seedState ?? new RunState($runId, RunStatus::Queued);
            $products = $this->newProducts($seed);
            $this->transcriptProjector->reset();

            /** @var array<int, ColdReconstructionCheckpoint> $checkpoints */
            $checkpoints = [];
            $filteredCount = 0;
            $sawEvent = false;
            $activeCheckpointTurn = null;

            foreach ($eventSource() as $event) {
                $sawEvent = true;
                if ($event->runId !== $runId) {
                    throw new \RuntimeException(\sprintf('Cannot reconstruct run %s: event belongs to another run.', $runId));
                }

                $planner->observe($event);

                if ($planner->isTurnSeedingCommandEvent($event)) {
                    $planner->deferSeed($event);
                    continue;
                }

                if (RunEventTypeEnum::TurnAdvanced->value === $event->type) {
                    $createdTurnNo = (int) ($event->payload['turn_no'] ?? $event->turnNo);
                    $mappedSeeds = $planner->mapDeferredSeeds($createdTurnNo);
                    $includeTurn = $createdTurnNo > 0 && $planner->shouldIncludeTurn(
                        $createdTurnNo,
                        $this->workingPosition($planner, $positionTurnNo),
                    );

                    if ($includeTurn) {
                        foreach ($mappedSeeds as $seedEvent) {
                            $this->applyIncludedEvent($products, $seedEvent, $filteredCount);
                        }
                        $this->applyIncludedEvent($products, $event, $filteredCount);
                        $activeCheckpointTurn = $createdTurnNo;
                        $checkpoints[$createdTurnNo] = $this->captureCheckpoint($products, $filteredCount);
                    } else {
                        $activeCheckpointTurn = null;
                    }

                    continue;
                }

                if (RunEventTypeEnum::HistoryPositionSet->value === $event->type) {
                    $planner->suppressUnmatchedPendingSeeds($event);
                }

                if (RunEventTypeEnum::HistoryTailDiscarded->value === $event->type) {
                    $after = (int) ($event->payload['after_turn_no'] ?? 0);
                    $planner->dropDeferredSeedsAfterDiscard($after);
                    foreach (array_keys($checkpoints) as $turnNo) {
                        if ($turnNo > $after) {
                            unset($checkpoints[$turnNo]);
                        }
                    }
                    if (isset($checkpoints[$after])) {
                        $this->restoreCheckpoint($products, $checkpoints[$after]);
                        $filteredCount = $checkpoints[$after]->filteredCount;
                        $activeCheckpointTurn = $after;
                    } elseif (0 === $after) {
                        $products = $this->newProducts($seed);
                        $this->transcriptProjector->reset();
                        $filteredCount = 0;
                        $activeCheckpointTurn = null;
                    } else {
                        $activeCheckpointTurn = null;
                    }
                } else {
                    // Flush retained unmapped seeds before later same-turn work so
                    // shell/tool state stays coherent. history_select already suppressed
                    // unmatched pending launches above.
                    $this->flushUnmappedDeferredSeeds(
                        $planner,
                        $products,
                        $filteredCount,
                        $activeCheckpointTurn,
                        $checkpoints,
                        $positionTurnNo,
                    );
                }

                if (!$planner->shouldIncludeEvent($event, $this->workingPosition($planner, $positionTurnNo))) {
                    continue;
                }

                $this->applyIncludedEvent($products, $event, $filteredCount);
                if (null !== $activeCheckpointTurn) {
                    $checkpoints[$activeCheckpointTurn] = $this->captureCheckpoint($products, $filteredCount);
                }
            }

            $this->flushUnmappedDeferredSeeds(
                $planner,
                $products,
                $filteredCount,
                $activeCheckpointTurn,
                $checkpoints,
                $positionTurnNo,
            );

            if (!$sawEvent || 0 === $planner->maxSeq()) {
                return $this->emptyResult($runId, $seedState, $publishSharedState, $publishHistory);
            }

            $scannedMaxSeq = $planner->maxSeq();
            if (null !== $expectedMaxSeq && $expectedMaxSeq !== $scannedMaxSeq) {
                throw new \RuntimeException(\sprintf('Cannot reconstruct run %s: expected max seq %d does not match scanned seq %d.', $runId, $expectedMaxSeq, $scannedMaxSeq));
            }

            $historySnapshot = $planner->finishSnapshot();
            $resolvedPosition = $positionTurnNo ?? $historySnapshot->history->positionTurnNo;
            if (null !== $positionTurnNo) {
                if ($positionTurnNo < 0) {
                    throw new \RuntimeException(\sprintf('Cannot reconstruct run %s: invalid selected position %d.', $runId, $positionTurnNo));
                }
                if (0 !== $positionTurnNo && !\in_array($positionTurnNo, $historySnapshot->history->retainedTurnNos, true)) {
                    throw new \RuntimeException(\sprintf('Cannot reconstruct run %s: selected position %d is not retained.', $runId, $positionTurnNo));
                }
            }

            if ($resolvedPosition !== $planner->tipTurnNo()) {
                if (0 === $resolvedPosition) {
                    $products = $this->newProducts($seed);
                    $this->transcriptProjector->reset();
                    $filteredCount = 0;
                } elseif (!isset($checkpoints[$resolvedPosition])) {
                    throw new \RuntimeException(\sprintf('Cannot reconstruct run %s: missing checkpoint for selected position %d.', $runId, $resolvedPosition));
                } else {
                    $this->restoreCheckpoint($products, $checkpoints[$resolvedPosition]);
                    $filteredCount = $checkpoints[$resolvedPosition]->filteredCount;
                }
            }

            $historyForPublish = $historySnapshot->history;
            if ($historyForPublish->positionTurnNo !== $resolvedPosition) {
                $historyForPublish = new HistoryDTO(
                    retainedTurnNos: $historyForPublish->retainedTurnNos,
                    promptsByTurnNo: $historyForPublish->promptsByTurnNo,
                    positionTurnNo: $resolvedPosition,
                );
                $historySnapshot = new HistoryProjectionSnapshot(
                    history: $historyForPublish,
                    lastSeq: $scannedMaxSeq,
                    initialPrompt: $historySnapshot->initialPrompt,
                    pendingHumanPrompt: $historySnapshot->pendingHumanPrompt,
                    eventCount: $historySnapshot->eventCount,
                    sanitizedEventTail: $historySnapshot->sanitizedEventTail,
                );
            } elseif ($historySnapshot->lastSeq !== $scannedMaxSeq) {
                $historySnapshot = new HistoryProjectionSnapshot(
                    history: $historyForPublish,
                    lastSeq: $scannedMaxSeq,
                    initialPrompt: $historySnapshot->initialPrompt,
                    pendingHumanPrompt: $historySnapshot->pendingHumanPrompt,
                    eventCount: $historySnapshot->eventCount,
                    sanitizedEventTail: $historySnapshot->sanitizedEventTail,
                );
            }

            $runState = $products->runStateApp->snapshot()->with([
                'turnNo' => $resolvedPosition,
                'lastSeq' => $scannedMaxSeq,
            ]);

            $result = new SessionColdReconstructionResult(
                runId: $runId,
                lastSeq: $scannedMaxSeq,
                historySnapshot: $historySnapshot,
                runState: $runState,
                transcript: new SessionTranscriptSnapshotDTO(
                    $this->transcriptProjector->blocks(),
                    $products->resume(),
                ),
                isShellOnlySession: $planner->isShellOnlySession(),
                terminalActivityReason: $planner->terminalActivityReason(),
                suppressTerminalActivityForInProgressCompaction: $planner->shouldSuppressTerminalActivityForInProgressCompaction(),
            );

            if ($publishHistory || $publishSharedState) {
                $this->publishShared($result, $publishHistory, $publishSharedState);
            }

            $this->logger->info('session_cold_reconstruction.completed', [
                'run_id' => $runId,
                'component' => 'cold_reconstruction',
                'event_type' => 'session_cold_reconstruction.completed',
                'last_seq' => $scannedMaxSeq,
                'position_turn_no' => $resolvedPosition,
                'filtered_event_count' => $filteredCount,
                'message_count' => \count($runState->messages),
                'transcript_block_count' => \count($result->transcript->transcriptBlocks),
                'publish_shared_state' => $publishSharedState,
                ...ProcessMemorySnapshotLogger::transcriptScalars($result->transcript->transcriptBlocks),
            ]);

            return $result;
        } finally {
            RunLogContext::leave();
        }
    }

    /**
     * @param array<int, ColdReconstructionCheckpoint> $checkpoints
     */
    private function flushUnmappedDeferredSeeds(
        HistoryReplayStreamPlanner $planner,
        ColdReconstructionProducts $products,
        int &$filteredCount,
        ?int $activeCheckpointTurn,
        array &$checkpoints,
        ?int $positionTurnNo,
    ): void {
        foreach ($planner->drainDeferredSeeds() as $seedEvent) {
            if (!$planner->shouldIncludeTurn($seedEvent->turnNo, $this->workingPosition($planner, $positionTurnNo))) {
                continue;
            }
            $this->applyIncludedEvent($products, $seedEvent, $filteredCount);
            if (null !== $activeCheckpointTurn) {
                $checkpoints[$activeCheckpointTurn] = $this->captureCheckpoint($products, $filteredCount);
            }
        }
    }

    private function publishShared(
        SessionColdReconstructionResult $result,
        bool $publishHistory,
        bool $publishSharedState,
    ): void {
        $this->runLockManager->synchronized($result->runId, function () use ($result, $publishHistory, $publishSharedState): void {
            if (null !== $this->runStateStore) {
                $cachedState = $this->runStateStore->find($result->runId);
                if (null !== $cachedState && $cachedState->lastSeq > $result->runState->lastSeq) {
                    throw new \RuntimeException(\sprintf('Cannot publish cold reconstruction for run %s over newer shared state seq %d.', $result->runId, $cachedState->lastSeq));
                }
            }

            $cachedHistory = $this->readHistorySnapshotOrNull($result->runId);
            if (null !== $cachedHistory && $cachedHistory->lastSeq > $result->historySnapshot->lastSeq) {
                throw new \RuntimeException(\sprintf('Cannot publish cold reconstruction for run %s over newer shared history seq %d.', $result->runId, $cachedHistory->lastSeq));
            }

            if ($publishHistory) {
                // Never invalidate first: that would erase a newer concurrent writer.
                $this->historyProjectionStore->remember($result->runId, $result->historySnapshot);
            }

            if ($publishSharedState && null !== $this->activeRunContext) {
                $this->activeRunContext->remember($result->runState);
            } elseif ($publishSharedState && null !== $this->runStateStore) {
                $this->runStateStore->remember($result->runState);
            }
        });
    }

    private function readHistorySnapshotOrNull(string $runId): ?HistoryProjectionSnapshot
    {
        return $this->historyProjectionStore->find($runId);
    }

    private function workingPosition(HistoryReplayStreamPlanner $planner, ?int $positionTurnNo): int
    {
        // During the single archive pass, always project the live retained tip.
        // Explicit selected positions are applied after the scan via checkpoints.
        // Using the selected position mid-scan would drop ancestors before that
        // turn is retained (retainedTurnNosThrough returns [] until then).
        unset($positionTurnNo);

        return $planner->tipTurnNo();
    }

    private function applyIncludedEvent(
        ColdReconstructionProducts $products,
        RunEvent $event,
        int &$filteredCount,
    ): void {
        ++$filteredCount;
        $products->runStateApp->apply($event);

        $runtimeEvent = $this->eventMapper->toRuntimeEvent($event);
        if (null === $runtimeEvent) {
            return;
        }

        $this->transcriptProjector->accept($runtimeEvent);
        $products->activityReducer->apply($runtimeEvent);
        $this->accumulateResumeProjection(
            $runtimeEvent,
            $products->usageInputTokens,
            $products->usageOutputTokens,
            $products->usageTotalCost,
            $products->usageLatestInputTokens,
            $products->usageCacheReadTokens,
            $products->usageCacheCreationTokens,
            $products->usageHasCacheTelemetry,
            $products->queuedUserMessages,
            $products->subagentProgressSnapshots,
            $products->llmRetryWorkingMessage,
        );
    }

    private function captureCheckpoint(ColdReconstructionProducts $products, int $filteredCount): ColdReconstructionCheckpoint
    {
        return new ColdReconstructionCheckpoint(
            runState: $products->runStateApp->snapshot(),
            completedToolResultsByCallId: $products->runStateApp->completedToolResultsByCallId(),
            transcriptBlocks: $this->transcriptProjector->blocks(),
            resume: $products->resume(),
            filteredCount: $filteredCount,
        );
    }

    /**
     * @param array<string, string>                   $queuedUserMessages
     * @param list<SubagentProgressSnapshotInterface> $subagentProgressSnapshots
     */
    private function accumulateResumeProjection(
        RuntimeEvent $event,
        int &$usageInputTokens,
        int &$usageOutputTokens,
        float &$usageTotalCost,
        int &$usageLatestInputTokens,
        int &$usageCacheReadTokens,
        int &$usageCacheCreationTokens,
        bool &$usageHasCacheTelemetry,
        array &$queuedUserMessages,
        array &$subagentProgressSnapshots,
        ?string &$llmRetryWorkingMessage,
    ): void {
        if (RuntimeEventTypeEnum::LlmRequestRetrying->value === $event->type) {
            $attempt = \is_int($event->payload['attempt'] ?? null) ? $event->payload['attempt'] : 0;
            $maxAttempts = \is_int($event->payload['max_attempts'] ?? null) ? $event->payload['max_attempts'] : 0;
            $delayMs = \is_int($event->payload['delay_ms'] ?? null) ? $event->payload['delay_ms'] : 0;
            $reason = \is_string($event->payload['reason'] ?? null) && '' !== $event->payload['reason']
                ? $event->payload['reason']
                : 'LLM provider request failed.';
            $delayLabel = $delayMs >= 1000
                ? \sprintf('%.1fs', $delayMs / 1000)
                : \sprintf('%dms', $delayMs);
            $llmRetryWorkingMessage = \sprintf(
                'Retrying LLM %d/%d in %s — %s',
                max(1, $attempt),
                max(1, $maxAttempts),
                $delayLabel,
                mb_substr($reason, 0, 120),
            );
        } elseif (
            RuntimeEventTypeEnum::AssistantMessageStarted->value === $event->type
            || RuntimeEventTypeEnum::AssistantTextStarted->value === $event->type
            || RuntimeEventTypeEnum::AssistantThinkingStarted->value === $event->type
        ) {
            $llmRetryWorkingMessage = null;
        }

        if (RuntimeEventTypeEnum::RunHistoryPositionChanged->value === $event->type) {
            $queuedUserMessages = [];
        }

        if (\in_array($event->type, [
            RuntimeEventTypeEnum::RunCancelled->value,
            RuntimeEventTypeEnum::TurnCancelled->value,
            RuntimeEventTypeEnum::RunFailed->value,
            RuntimeEventTypeEnum::TurnFailed->value,
        ], true)) {
            $queuedUserMessages = [];
            $llmRetryWorkingMessage = null;
        }

        if (RuntimeEventTypeEnum::UserMessageQueued->value === $event->type) {
            $key = (string) ($event->payload['idempotency_key'] ?? '');
            if ('' !== $key) {
                $queuedUserMessages[$key] = (string) ($event->payload['text'] ?? '');
            }
        } elseif (RuntimeEventTypeEnum::UserMessageSubmitted->value === $event->type) {
            $key = (string) ($event->payload['idempotency_key'] ?? '');
            if ('' !== $key) {
                unset($queuedUserMessages[$key]);
            }
        }

        if (RuntimeEventTypeEnum::AssistantMessageCompleted->value === $event->type) {
            $usage = $event->payload['usage'] ?? [];
            if (\is_array($usage)) {
                $usageLatestInputTokens = (int) ($usage['input_tokens'] ?? $usage['prompt_tokens'] ?? 0);
                $usageInputTokens += $usageLatestInputTokens;
                $usageOutputTokens += (int) ($usage['output_tokens'] ?? $usage['completion_tokens'] ?? 0);
                $turnCacheRead = $usage['cache_read_tokens'] ?? $usage['cached_tokens'] ?? null;
                if (null !== $turnCacheRead) {
                    $usageCacheReadTokens += (int) $turnCacheRead;
                    $usageHasCacheTelemetry = true;
                }
                $turnCacheCreation = $usage['cache_creation_tokens'] ?? null;
                if (null !== $turnCacheCreation) {
                    $usageCacheCreationTokens += (int) $turnCacheCreation;
                }
                $cost = $usage['cost'] ?? $usage['total_cost'] ?? null;
                if (\is_float($cost) || \is_int($cost)) {
                    $usageTotalCost += (float) $cost;
                }
            }
        }

        if (str_contains($event->type, 'tool_execution') && \array_key_exists('subagent_progress', $event->payload)) {
            $raw = $event->payload['subagent_progress'];
            if (\is_array($raw)) {
                $snapshot = $this->denormalizer->denormalize($raw, SubagentProgressSnapshotInterface::class);
                if ($snapshot instanceof SubagentProgressSnapshotInterface) {
                    $subagentProgressSnapshots[] = $snapshot;
                }
            }
        }
    }

    private function emptyResult(
        string $runId,
        ?RunState $seedState,
        bool $publishSharedState,
        bool $publishHistory,
    ): SessionColdReconstructionResult {
        $historySnapshot = $publishHistory
            ? $this->historyProjectionStore->initializeFromEvents($runId, [])
            : new HistoryProjectionSnapshot(
                history: new HistoryDTO([], [], 0),
                lastSeq: 0,
            );
        $runState = ($seedState ?? new RunState($runId, RunStatus::Queued))->with([
            'turnNo' => 0,
            'lastSeq' => 0,
            'messages' => [],
            'pendingToolCalls' => [],
            'status' => RunStatus::Queued,
        ]);

        $result = new SessionColdReconstructionResult(
            runId: $runId,
            lastSeq: 0,
            historySnapshot: $historySnapshot,
            runState: $runState,
            transcript: new SessionTranscriptSnapshotDTO([], SessionResumeProjectionDTO::empty()),
            isShellOnlySession: false,
            terminalActivityReason: null,
            suppressTerminalActivityForInProgressCompaction: false,
        );

        if ($publishHistory || $publishSharedState) {
            $this->publishShared($result, $publishHistory, $publishSharedState);
        }

        return $result;
    }

    private function newProducts(RunState $seed): ColdReconstructionProducts
    {
        return new ColdReconstructionProducts(
            runStateApp: $this->runStateReducer->startIncremental($seed),
            activityReducer: new ResumeActivityReducer(),
        );
    }

    private function restoreCheckpoint(
        ColdReconstructionProducts $products,
        ColdReconstructionCheckpoint $checkpoint,
    ): void {
        $products->runStateApp->restore(
            $checkpoint->runState,
            $checkpoint->completedToolResultsByCallId,
        );
        $products->usageInputTokens = $checkpoint->resume->usageInputTokens;
        $products->usageOutputTokens = $checkpoint->resume->usageOutputTokens;
        $products->usageTotalCost = $checkpoint->resume->usageTotalCost;
        $products->usageLatestInputTokens = $checkpoint->resume->usageLatestInputTokens;
        $products->usageCacheReadTokens = $checkpoint->resume->usageCacheReadTokens;
        $products->usageCacheCreationTokens = $checkpoint->resume->usageCacheCreationTokens;
        $products->usageHasCacheTelemetry = $checkpoint->resume->usageHasCacheTelemetry;
        $products->queuedUserMessages = $checkpoint->resume->queuedUserMessages;
        $products->subagentProgressSnapshots = $checkpoint->resume->subagentProgressSnapshots;
        $products->llmRetryWorkingMessage = $checkpoint->resume->llmRetryWorkingMessage;
        $products->activityReducer = new ResumeActivityReducer(
            $checkpoint->resume->activity,
            $checkpoint->resume->isCompacting,
        );
        $this->transcriptProjector->replaceProjectedBlocks($checkpoint->transcriptBlocks);
    }
}

/**
 * @internal
 */
final class ColdReconstructionProducts
{
    public int $usageInputTokens = 0;

    public int $usageOutputTokens = 0;

    public float $usageTotalCost = 0.0;

    public int $usageLatestInputTokens = 0;

    public int $usageCacheReadTokens = 0;

    public int $usageCacheCreationTokens = 0;

    public bool $usageHasCacheTelemetry = false;

    /** @var array<string, string> */
    public array $queuedUserMessages = [];

    /** @var list<SubagentProgressSnapshotInterface> */
    public array $subagentProgressSnapshots = [];

    public ?string $llmRetryWorkingMessage = null;

    public function __construct(
        public RunStateIncrementalApplication $runStateApp,
        public ResumeActivityReducer $activityReducer,
    ) {
    }

    public function resume(): SessionResumeProjectionDTO
    {
        return new SessionResumeProjectionDTO(
            usageInputTokens: $this->usageInputTokens,
            usageOutputTokens: $this->usageOutputTokens,
            usageTotalCost: $this->usageTotalCost,
            usageLatestInputTokens: $this->usageLatestInputTokens,
            usageCacheReadTokens: $this->usageCacheReadTokens,
            usageCacheCreationTokens: $this->usageCacheCreationTokens,
            usageHasCacheTelemetry: $this->usageHasCacheTelemetry,
            queuedUserMessages: $this->queuedUserMessages,
            subagentProgressSnapshots: $this->subagentProgressSnapshots,
            llmRetryWorkingMessage: $this->llmRetryWorkingMessage,
            activity: $this->activityReducer->activity(),
            isCompacting: $this->activityReducer->isCompacting(),
        );
    }
}

/**
 * @internal
 */
final readonly class ColdReconstructionCheckpoint
{
    /**
     * @param array<string, \Ineersa\AgentCore\Domain\Message\ToolCallResult> $completedToolResultsByCallId
     * @param list<TranscriptBlock>                                           $transcriptBlocks
     */
    public function __construct(
        public RunState $runState,
        public array $completedToolResultsByCallId,
        public array $transcriptBlocks,
        public SessionResumeProjectionDTO $resume,
        public int $filteredCount,
    ) {
    }
}
