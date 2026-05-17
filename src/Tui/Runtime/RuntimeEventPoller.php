<?php

declare(strict_types=1);

namespace Ineersa\Tui\Runtime;

use Ineersa\CodingAgent\Runtime\Contract\AgentSessionClient;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEvent;
use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Ineersa\CodingAgent\Session\TranscriptEntry as PersistedTranscriptEntry;
use Ineersa\Tui\Transcript\TranscriptEntry;

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
                if ($seq <= $state->lastSeq) {
                    continue;
                }
                $state->lastSeq = $seq;
                $hasNew = true;

                // Persist the runtime event
                $this->sessionStore->appendRuntimeEvent(
                    $state->sessionId,
                    $runtimeEvent->toArray(),
                );

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
        } catch (\Throwable) {
            // Silently skip polling errors; show nothing to user
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

        return match ($event->type) {
            'run_started' => new TranscriptEntry(
                text: \sprintf('Run started: %s', $payload['prompt'] ?? ''),
                role: 'system',
                style: 'accent',
            ),
            'message_update' => new TranscriptEntry(
                text: mb_substr((string) ($payload['content'] ?? ($payload['text'] ?? '')), 0, 500),
                role: 'assistant',
                style: 'message_update',
            ),
            'message_end' => new TranscriptEntry(
                text: '(end of message)',
                role: 'assistant',
                style: 'muted',
            ),
            'tool_execution_start' => new TranscriptEntry(
                text: \sprintf('%s %s', (string) ($payload['tool'] ?? 'tool'), (string) ($payload['input'] ?? '')),
                role: 'tool',
                style: 'tool_start',
            ),
            'tool_execution_end' => new TranscriptEntry(
                text: \sprintf('%s %s', (string) ($payload['tool'] ?? 'tool'), (string) ($payload['summary'] ?? 'done')),
                role: 'tool',
                style: 'tool_end',
            ),
            'turn_start', 'turn_end', 'agent_start', 'agent_end' => null,
            default => new TranscriptEntry(
                text: \sprintf('· %s', $event->type),
                role: 'system',
                style: 'muted',
            ),
        };
    }
}
