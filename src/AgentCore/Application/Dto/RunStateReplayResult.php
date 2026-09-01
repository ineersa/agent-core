<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Application\Dto;

use Ineersa\AgentCore\Domain\Run\RunState;

/**
 * Result of rebuilding {@see RunState} from canonical events.
 *
 * Callers use {@see $rebuiltState} when a refresh was required; null means
 * either no events exist or the stored state is already current.
 */
final readonly class RunStateReplayResult
{
    public function __construct(
        public ?RunState $rebuiltState,
    ) {
    }

    /**
     * Factory for a no-events result — nothing to replay.
     */
    public static function noEvents(): self
    {
        return new self(rebuiltState: null);
    }

    /**
     * Factory for a current (not stale) result — no rebuild needed.
     */
    public static function current(): self
    {
        return new self(rebuiltState: null);
    }

    /**
     * Factory for a rebuilt result.
     */
    public static function rebuilt(RunState $state): self
    {
        return new self(rebuiltState: $state);
    }
}
