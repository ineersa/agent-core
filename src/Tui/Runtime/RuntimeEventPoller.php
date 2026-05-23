<?php

declare(strict_types=1);

namespace Ineersa\Tui\Runtime;

use Ineersa\CodingAgent\Runtime\Contract\AgentSessionClient;
use Ineersa\CodingAgent\Runtime\Contract\TranscriptProjectorInterface;
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
        private readonly TranscriptProjectorInterface $projector,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Poll for new runtime events and synchronize projected transcript blocks.
     *
     * @return list<TranscriptBlock>|null Changed/new transcript blocks, or null if nothing new
     */
    public function poll(TuiSessionState $state, AgentSessionClient $client): ?array
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

                self::extractFooterUsage($state, $runtimeEvent);
                self::updateActivity($state, $runtimeEvent);
                $this->projector->accept($runtimeEvent->toArray());

                if (!$processingRemoved) {
                    self::removeProcessingPlaceholder($state);
                    $processingRemoved = true;
                }
            }

            if (!$hasNew) {
                return null;
            }

            return self::synchronizeProjectedBlocks($state, $this->projector->blocks());
        } catch (\Throwable $e) {
            ++$state->runtimePollErrorCount;
            $state->lastRuntimePollError = $e->getMessage();

            $this->logger->warning('RuntimeEventPoller polling error', [
                'exception' => $e,
                'run_id' => $state->handle->runId,
                'consecutive_errors' => $state->runtimePollErrorCount,
            ]);

            if (!$this->isFatalPollingError($e) && $state->runtimePollErrorCount < 3) {
                return null;
            }

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

    /**
     * Update TUI activity state based on the runtime event type.
     *
     * This is the authoritative transition source for run activity.
     * SubmitListener sets Starting/Cancelling optimistically on send/cancel;
     * this method confirms and advances to the confirmed state from events.
     */
    private static function updateActivity(TuiSessionState $state, RuntimeEvent $event): void
    {
        if ($state->activity->isTerminal()) {
            return; // Terminal states are never overridden by later events.
        }

        $type = $event->type;

        match ($type) {
            RuntimeEventTypeEnum::RunStarted->value,
            RuntimeEventTypeEnum::TurnStarted->value,
            RuntimeEventTypeEnum::TurnCompleted->value,
            RuntimeEventTypeEnum::AssistantMessageStarted->value,
            RuntimeEventTypeEnum::AssistantTextStarted->value,
            RuntimeEventTypeEnum::AssistantTextDelta->value,
            RuntimeEventTypeEnum::AssistantTextCompleted->value,
            RuntimeEventTypeEnum::AssistantThinkingStarted->value,
            RuntimeEventTypeEnum::AssistantThinkingDelta->value,
            RuntimeEventTypeEnum::AssistantThinkingCompleted->value,
            RuntimeEventTypeEnum::AssistantMessageCompleted->value,
            RuntimeEventTypeEnum::ToolCallStarted->value,
            RuntimeEventTypeEnum::ToolCallArgumentsDelta->value,
            RuntimeEventTypeEnum::ToolCallArgumentsCompleted->value,
            RuntimeEventTypeEnum::ToolExecutionStarted->value,
            RuntimeEventTypeEnum::ToolExecutionOutputDelta->value,
            RuntimeEventTypeEnum::ToolExecutionCompleted->value,
            RuntimeEventTypeEnum::ToolExecutionFailed->value,
            RuntimeEventTypeEnum::UserMessageSubmitted->value,
            RuntimeEventTypeEnum::HumanInputAnswered->value,
            RuntimeEventTypeEnum::ApprovalApproved->value,
            RuntimeEventTypeEnum::ApprovalRejected->value,
            RuntimeEventTypeEnum::HumanInputRejected->value => $state->activity = RunActivityStateEnum::Running,

            RuntimeEventTypeEnum::HumanInputRequested->value,
            RuntimeEventTypeEnum::ApprovalRequested->value => $state->activity = RunActivityStateEnum::WaitingHuman,

            RuntimeEventTypeEnum::CancellationRequested->value,
            RuntimeEventTypeEnum::OperationCancelled->value,
            RuntimeEventTypeEnum::ToolExecutionCancelled->value => $state->activity = RunActivityStateEnum::Cancelling,

            RuntimeEventTypeEnum::RunCompleted->value => $state->activity = RunActivityStateEnum::Completed,

            RuntimeEventTypeEnum::RunFailed->value,
            RuntimeEventTypeEnum::TurnFailed->value,
            RuntimeEventTypeEnum::AssistantMessageFailed->value => $state->activity = RunActivityStateEnum::Failed,

            RuntimeEventTypeEnum::RunCancelled->value,
            RuntimeEventTypeEnum::TurnCancelled->value => $state->activity = RunActivityStateEnum::Cancelled,

            default => null, // No transition for unknown/streaming/internal events
        };
    }

    /**
     * Extract token usage and cost from runtime events, track LLM timing,
     * and accumulate into footer state.
     */
    private static function extractFooterUsage(TuiSessionState $state, RuntimeEvent $event): void
    {
        // Track LLM start time from the first text delta or text started event
        if (RuntimeEventTypeEnum::AssistantTextStarted->value === $event->type) {
            if (0.0 === $state->llmStartTime) {
                $state->llmStartTime = microtime(true);
            }

            return;
        }

        if (RuntimeEventTypeEnum::AssistantMessageCompleted->value !== $event->type) {
            return;
        }

        // Record LLM end time when the response completes
        $state->llmEndTime = microtime(true);

        $usage = $event->payload['usage'] ?? [];
        if (!\is_array($usage)) {
            return;
        }

        $state->inputTokens += (int) ($usage['input_tokens'] ?? $usage['prompt_tokens'] ?? 0);
        $state->outputTokens += (int) ($usage['output_tokens'] ?? $usage['completion_tokens'] ?? 0);

        $cost = $usage['cost'] ?? $usage['total_cost'] ?? null;
        if (\is_float($cost) || \is_int($cost)) {
            $state->totalCost += (float) $cost;
        }
    }
}
