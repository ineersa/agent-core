<?php

declare(strict_types=1);

namespace Ineersa\Tui\Runtime;

use Ineersa\CodingAgent\Runtime\Contract\AgentSessionClient;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEvent;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventTypeEnum;
use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Ineersa\CodingAgent\Session\TranscriptEntry as PersistedTranscriptEntry;
use Ineersa\Tui\Transcript\TranscriptEntry;
use Psr\Log\LoggerInterface;

/**
 * Polls AgentSessionClient for new runtime events on each TUI tick.
 *
 * Handles:
 *   - Throttled polling (POLL_INTERVAL)
 *   - Sequence-based deduplication
 *   - Event → transcript entry mapping (plain model, no theme)
 *   - Session persistence (runtime events + transcript entries)
 *
 * Extracted from the inline tick listener in InteractiveMode::run().
 */
final class RuntimeEventPoller
{
    /** @var float Polling interval in seconds (50ms) */
    private const float POLL_INTERVAL = 0.05;

    public function __construct(
        private readonly HatfieldSessionStore $sessionStore,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Poll for new runtime events and return plain transcript entries.
     *
     * @param TuiSessionState    $state  Mutable session state
     * @param AgentSessionClient $client Runtime client
     *
     * @return list<TranscriptEntry>|null New transcript entries, or null if nothing new
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
            $eventsIter = $client->events($state->handle->runId);
            $events = $eventsIter instanceof \Traversable
                ? iterator_to_array($eventsIter)
                : $eventsIter;

            if ([] === $events) {
                return null;
            }

            $hasNew = false;
            $newEntries = [];
            $processingRemoved = false;

            foreach ($events as $runtimeEvent) {
                /** @var RuntimeEvent $runtimeEvent */
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

                // Persist the runtime event
                $this->sessionStore->appendRuntimeEvent(
                    $state->sessionId,
                    $runtimeEvent->toArray(),
                );

                // Extract usage/footer data from runtime events
                self::extractFooterUsage($state, $runtimeEvent);

                // Remove "Processing..." placeholder on first real event
                if (!$processingRemoved) {
                    $lastIdx = \count($state->transcript) - 1;
                    if ($lastIdx >= 0 && str_contains($state->transcript[$lastIdx]->text, 'Processing...')) {
                        array_pop($state->transcript);
                    }
                    $processingRemoved = true;
                }

                // Map event to plain transcript entry
                $entry = self::formatEventToEntry($runtimeEvent);
                if (null !== $entry) {
                    $newEntries[] = $entry;
                    $state->transcript[] = $entry;
                    $this->sessionStore->appendTranscriptEntry(
                        $state->sessionId,
                        new PersistedTranscriptEntry(
                            role: $entry->role,
                            text: $entry->text,
                            meta: [
                                'run_id' => $runtimeEvent->runId,
                                'seq' => $seq,
                                'event_type' => $runtimeEvent->type,
                            ],
                        ),
                    );
                }
            }

            return $hasNew ? $newEntries : null;
        } catch (\Throwable $e) {
            $this->logger->warning('RuntimeEventPoller polling error', [
                'exception' => $e,
                'run_id' => $state->handle->runId,
            ]);

            return null;
        }
    }

    /**
     * Convert a RuntimeEvent into a plain transcript entry (no theme colors).
     *
     * Returns null if the event type should not appear in the transcript.
     */
    public static function formatEventToEntry(RuntimeEvent $event): ?TranscriptEntry
    {
        $payload = $event->payload;
        $t = RuntimeEventTypeEnum::tryFrom($event->type);

        return match ($t) {
            RuntimeEventTypeEnum::RunStarted => new TranscriptEntry(
                text: \sprintf('Run started%s', isset($payload['step_id']) ? ' — '.$payload['step_id'] : ''),
                role: 'system',
                style: 'accent',
            ),
            RuntimeEventTypeEnum::RunCompleted,
            RuntimeEventTypeEnum::RunCancelled,
            RuntimeEventTypeEnum::RunFailed => new TranscriptEntry(
                text: \sprintf('Run %s', str_replace('run.', '', $t->value)),
                role: 'system',
                style: 'muted',
            ),
            RuntimeEventTypeEnum::TurnStarted,
            RuntimeEventTypeEnum::TurnCompleted,
            RuntimeEventTypeEnum::TurnFailed,
            RuntimeEventTypeEnum::TurnCancelled => null,
            RuntimeEventTypeEnum::AssistantMessageCompleted => new TranscriptEntry(
                text: mb_substr((string) ($payload['text'] ?? ''), 0, 500),
                role: 'assistant',
                style: 'message_update',
            ),
            RuntimeEventTypeEnum::AssistantMessageFailed => new TranscriptEntry(
                text: \sprintf('Error: %s', mb_substr((string) ($payload['text'] ?? 'Unknown error'), 0, 200)),
                role: 'system',
                style: 'error',
            ),
            RuntimeEventTypeEnum::ToolExecutionStarted => new TranscriptEntry(
                text: \sprintf('%s', (string) ($payload['tool_name'] ?? 'tool')),
                role: 'tool',
                style: 'tool_start',
            ),
            RuntimeEventTypeEnum::ToolExecutionCompleted,
            RuntimeEventTypeEnum::ToolExecutionFailed => new TranscriptEntry(
                text: \sprintf('%s %s',
                    (string) ($payload['tool_name'] ?? (string) ($payload['tool_call_id'] ?? 'tool')),
                    RuntimeEventTypeEnum::ToolExecutionFailed === $t ? '(failed)' : 'done',
                ),
                role: 'tool',
                style: 'tool_end',
            ),
            RuntimeEventTypeEnum::HumanInputRequested => new TranscriptEntry(
                text: \sprintf('? %s', (string) ($payload['prompt'] ?? 'Human input required.')),
                role: 'system',
                style: 'accent',
            ),
            RuntimeEventTypeEnum::CancellationRequested => new TranscriptEntry(
                text: 'Cancelling…',
                role: 'system',
                style: 'muted',
            ),
            RuntimeEventTypeEnum::StatusUpdated => new TranscriptEntry(
                text: \sprintf('· %s', $payload['debug.raw_type'] ?? $event->type),
                role: 'system',
                style: 'muted',
            ),
            default => new TranscriptEntry(
                text: \sprintf('· %s', $event->type),
                role: 'system',
                style: 'muted',
            ),
        };
    }

    /**
     * Extract token usage and cost from runtime events and accumulate into footer state.
     *
     * Called for every runtime event during polling. Only llm_step_completed events
     * carry usage/cost metadata.
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

        // Accumulate cost if the provider returns it in the usage payload
        $cost = $usage['cost'] ?? $usage['total_cost'] ?? null;
        if (\is_float($cost) || \is_int($cost)) {
            $state->totalCost += (float) $cost;
        }
    }
}
