<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\ProjectionPipeline;

use Ineersa\CodingAgent\Runtime\Projection\TranscriptProjectionState;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEvent;

/**
 * Event DTO dispatched through Symfony's EventDispatcher to projection
 * subscribers.
 *
 * Carries the typed {@see RuntimeEvent} plus a reference to the shared projection
 * state so subscribers can mutate blocks through the state holder.
 *
 * Plain PHP class — does not extend Symfony's deprecated Event base class.
 */
final class TranscriptProjectionEvent
{
    public function __construct(
        public readonly RuntimeEvent $runtimeEvent,
        public readonly TranscriptProjectionState $state,
    ) {
    }

    /** The event type string, used as the dispatcher event name. */
    public function type(): string
    {
        return $this->runtimeEvent->type;
    }

    /**
     * Shortcut for payload access.
     *
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        return $this->runtimeEvent->payload;
    }

    public function runId(): string
    {
        return $this->runtimeEvent->runId;
    }
}
