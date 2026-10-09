<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Application\Handler;

/** Transient queue acceptance outcome, not execution evidence or a retry obligation. */
final readonly class EffectDispatchFailedEvent
{
    public function __construct(
        public string $runId,
        public int $accepted,
        public int $failed,
    ) {
    }
}
