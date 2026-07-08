<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Fork;

use Ineersa\CodingAgent\Compaction\CompactionPreparationDTO;

/**
 * Synchronous summarization for fork-time virtual compaction.
 *
 * Produces summary text for a prepared partition without mutating parent
 * run state or emitting compaction events on the parent session.
 */
interface ForkSnapshotSummarizerInterface
{
    /**
     * @param string|null $activeSessionModel Parent session model (provider/model) for compaction overrides
     *
     * @throws ForkCompactionSummarizationException When summarization cannot produce usable text
     */
    public function summarize(CompactionPreparationDTO $preparation, ?string $activeSessionModel): string;
}
