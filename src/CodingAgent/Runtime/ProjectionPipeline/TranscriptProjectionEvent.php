<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\ProjectionPipeline;

use Ineersa\CodingAgent\Runtime\Projection\TranscriptProjectionState;

/**
 * Event DTO dispatched through Symfony's EventDispatcher to projection
 * subscribers.
 *
 * Carries the raw event array plus a reference to the shared projection
 * state so subscribers can mutate blocks through the state holder.
 *
 * Plain PHP class — does not extend Symfony's deprecated Event base class.
 *
 * @param array{type: string, runId: string, seq: int, payload: array<string, mixed>, v?: int} $rawEvent
 */
final class TranscriptProjectionEvent
{
    /**
     * @param array{type: string, runId: string, seq: int, payload: array<string, mixed>, v?: int} $rawEvent
     */
    public function __construct(
        public readonly array $rawEvent,
        public readonly TranscriptProjectionState $state,
    ) {
    }

    /** The event type string, used as the dispatcher event name. */
    public function type(): string
    {
        return $this->rawEvent['type'];
    }

    /**
     * Shortcut for payload access.
     *
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        return $this->rawEvent['payload'];
    }

    public function runId(): string
    {
        return $this->rawEvent['runId'];
    }
}
