<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\Contract;

use Ineersa\CodingAgent\Runtime\Projection\TranscriptBlock;

/**
 * Projects transcript blocks for a session leaf without exposing raw runtime events to TUI.
 */
interface SessionTranscriptProviderInterface
{
    /**
     * @return list<TranscriptBlock>
     */
    public function transcriptBlocksForLeaf(string $runId, int $leafTurnNo): array;
}
