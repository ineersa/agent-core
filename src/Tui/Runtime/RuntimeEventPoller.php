<?php

declare(strict_types=1);

namespace Ineersa\Tui\Runtime;

use Ineersa\CodingAgent\Runtime\Contract\AgentSessionClient;
use Ineersa\CodingAgent\Runtime\Contract\TranscriptProjectorInterface;
use Ineersa\CodingAgent\Runtime\Projection\TranscriptBlock;
use Ineersa\CodingAgent\Runtime\Projection\TranscriptBlockKindEnum;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEvent;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventTypeEnum;
use Ineersa\CodingAgent\Session\HatfieldSessionStore;

/**
 * Polls AgentSessionClient for new runtime events on each TUI tick.
 *
 * Runtime events are persisted unchanged, then fed through the transcript
 * projector so the UI renders projected TranscriptBlock DTOs instead of the
 * previous raw event log entries.
 */
final class RuntimeEventPoller
{
    /** Polling interval in seconds (50ms). */
    private const float POLL_INTERVAL = 0.05;

    public function __construct(
        private readonly HatfieldSessionStore $sessionStore,
        private readonly TranscriptProjectorInterface $projector,
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
                return null;
            }

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

                $this->sessionStore->appendRuntimeEvent(
                    $state->sessionId,
                    $runtimeEvent->toArray(),
                );

                self::extractFooterUsage($state, $runtimeEvent);
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
        } catch (\Throwable) {
            // Runtime polling must not break terminal rendering.
            return null;
        }
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
     * Extract token usage and cost from runtime events and accumulate into footer state.
     */
    private static function extractFooterUsage(TuiSessionState $state, RuntimeEvent $event): void
    {
        if (RuntimeEventTypeEnum::AssistantMessageCompleted->value !== $event->type) {
            return;
        }

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
