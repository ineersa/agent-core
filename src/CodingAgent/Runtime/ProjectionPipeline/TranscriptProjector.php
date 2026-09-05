<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\ProjectionPipeline;

use Ineersa\CodingAgent\Runtime\Contract\TranscriptProjectorInterface;
use Ineersa\CodingAgent\Runtime\Projection\TranscriptBlock;
use Ineersa\CodingAgent\Runtime\Projection\TranscriptChangeSet;
use Ineersa\CodingAgent\Runtime\Projection\TranscriptProjectionState;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEvent;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * Public API facade for the transcript projection.
 *
 * Accepts typed {@see RuntimeEvent} instances, wraps them in a
 * {@see TranscriptProjectionEvent}, and dispatches through Symfony's
 * EventDispatcher to family-grouped subscriber classes.
 */
final readonly class TranscriptProjector implements TranscriptProjectorInterface
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
     */
    public function accept(RuntimeEvent $event): void
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

    public function drainChanges(): TranscriptChangeSet
    {
        return $this->state->drainChanges();
    }

    /**
     * Reset all internal state so a fresh replay produces the same output.
     */
    public function reset(): void
    {
        $this->state->reset();
    }

    /**
     * @param list<TranscriptBlock> $blocks
     */
    public function replaceProjectedBlocks(array $blocks): void
    {
        $this->state->replaceBlocks($blocks);
    }
}
