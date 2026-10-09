<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Session\Replay;

use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\CodingAgent\Runtime\Projection\TranscriptBlock;

/** Owner-local result. Only blocks and scalar cut fields belong in a display transfer. */
final readonly class SessionReplayResultDTO
{
    /** @param list<TranscriptBlock> $blocks */
    public function __construct(
        public RunState $state,
        public array $blocks,
        public int $endOffset,
        public int $anchor,
    ) {
    }
}
