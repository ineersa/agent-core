<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\Contract;

use Ineersa\CodingAgent\Runtime\Projection\TranscriptBlock;
use Ineersa\CodingAgent\Runtime\Projection\TranscriptChangeSet;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEvent;

/**
 * Projects typed runtime events into transcript blocks.
 *
 * The interface lives at the runtime boundary so TUI code can consume the
 * projector without importing the concrete Symfony EventDispatcher pipeline.
 * Wire/JSONL arrays are decoded to {@see RuntimeEvent} once at the protocol
 * boundary; callers that already hold a RuntimeEvent pass it directly.
 */
interface TranscriptProjectorInterface
{
    public function accept(RuntimeEvent $event): void;

    /**
     * Ordered snapshot for bootstrap/resume/history-position replacement.
     *
     * @return list<TranscriptBlock>
     */
    public function blocks(): array;

    /**
     * Drain ordinary dirty changes since the previous drain without re-materializing
     * finalized history. Always incremental: live TUI state merges these deltas
     * into an existing snapshot (resume/history-position). Callers that need a full ordered
     * list use {@see blocks()} and build {@see TranscriptChangeSet::full()} themselves.
     */
    public function drainChanges(): TranscriptChangeSet;

    public function reset(): void;

    /**
     * Replace projected transcript blocks without replaying runtime events.
     *
     * Used after history-position wholesale rebuild so the live projector matches
     * the isolated snapshot and subsequent compaction.completed can find prior
     * retention markers. Does not mutate usage/activity; clears dirty tracking.
     *
     * @param list<TranscriptBlock> $blocks
     */
    public function replaceProjectedBlocks(array $blocks): void;
}
