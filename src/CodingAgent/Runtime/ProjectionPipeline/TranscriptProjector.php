<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\ProjectionPipeline;

use Ineersa\CodingAgent\Runtime\Projection\TranscriptBlock;
use Ineersa\CodingAgent\Runtime\Projection\TranscriptProjectionState;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * Public API facade for the transcript projection.
 *
 * Accepts raw runtime event arrays, wraps them in a
 * {@see TranscriptProjectionEvent}, and dispatches through Symfony's
 * EventDispatcher to family-grouped subscriber classes.  The facade
 * preserves the same public API (`accept(array)`, `blocks()`, `reset()`)
 * as the previous monolithic projector so callers are unaffected.
 */
final readonly class TranscriptProjector
{
    public function __construct(
        private EventDispatcherInterface $eventDispatcher,
        private TranscriptProjectionState $state,
    ) {
    }

    /**
     * Accept a single runtime event and update the projection.
     *
     * Unknown event types are silently ignored (no subscriber matches).
     *
     * @param array{type: string, runId: string, seq: int, payload: array<string, mixed>, v?: int} $event
     */
    public function accept(array $event): void
    {
        $projEvent = new TranscriptProjectionEvent($event, $this->state);
        $this->eventDispatcher->dispatch($projEvent, $projEvent->type());
    }

    /**
     * Return the current ordered list of transcript blocks.
     *
     * @return list<TranscriptBlock>
     */
    public function blocks(): array
    {
        return $this->state->blocks();
    }

    /**
     * Reset all internal state so a fresh replay produces the same output.
     */
    public function reset(): void
    {
        $this->state->reset();
    }
}
