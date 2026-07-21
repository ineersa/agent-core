<?php

declare(strict_types=1);

namespace Ineersa\Tui\Runtime;

use Ineersa\CodingAgent\Runtime\Projection\TranscriptBlock;

/**
 * Runtime-facing history port used to synchronize editor recall with transcript replay.
 *
 * The concrete navigation implementation lives with TUI listeners; the runtime
 * poller only needs to replace its entries after a branch leaf changes.
 */
interface PromptHistoryInterface
{
    /**
     * @param list<TranscriptBlock> $transcript
     */
    public function seedFrom(array $transcript): void;
}
