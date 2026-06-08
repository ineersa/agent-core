<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Application\Dto;

use Ineersa\AgentCore\Domain\Run\RunState;

/**
 * Result of rebuilding {@see RunState} from canonical events.
 *
 * Carries the rebuilt state and integrity diagnostics so callers
 * can decide whether to persist or reject a replayed state.
 */
final readonly class RunStateReplayResult
{
    /**
     * @param list<int> $missingSequences
     */
    public function __construct(
        public ?RunState $rebuiltState,
        public int $maxEventSeq,
        public int $eventCount,
        public bool $isContiguous,
        public array $missingSequences = [],
        public bool $rebuilt = false,
        public bool $hadEvents = false,
        public bool $wasStale = false,
    ) {
    }

    /**
     * Factory for a no-events result — nothing to replay.
     */
    public static function noEvents(): self
    {
        return new self(
            rebuiltState: null,
            maxEventSeq: 0,
            eventCount: 0,
            isContiguous: true,
            hadEvents: false,
        );
    }

    /**
     * Factory for a current (not stale) result — no rebuild needed.
     */
    public static function current(int $maxEventSeq, int $eventCount): self
    {
        return new self(
            rebuiltState: null,
            maxEventSeq: $maxEventSeq,
            eventCount: $eventCount,
            isContiguous: true,
            hadEvents: $eventCount > 0,
        );
    }

    /**
     * Factory for a rebuilt result.
     *
     * @param list<int> $missingSequences
     */
    public static function rebuilt(RunState $state, int $maxEventSeq, int $eventCount, bool $isContiguous, array $missingSequences = []): self
    {
        return new self(
            rebuiltState: $state,
            maxEventSeq: $maxEventSeq,
            eventCount: $eventCount,
            isContiguous: $isContiguous,
            missingSequences: $missingSequences,
            rebuilt: true,
            hadEvents: true,
            wasStale: true,
        );
    }
}
