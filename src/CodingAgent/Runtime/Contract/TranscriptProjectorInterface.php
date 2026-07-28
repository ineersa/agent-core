<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\Contract;

use Ineersa\CodingAgent\Runtime\Projection\TranscriptBlock;
use Ineersa\CodingAgent\Runtime\Projection\TranscriptChangeSet;

/**
 * Projects stable runtime event arrays into transcript blocks.
 *
 * The interface lives at the runtime boundary so TUI code can consume the
 * projector without importing the concrete Symfony EventDispatcher pipeline.
 */
interface TranscriptProjectorInterface
{
    /**
     * @param array{type: string, runId: string, seq: int, payload: array<string, mixed>, v?: int} $event
     */
    public function accept(array $event): void;

    /**
     * Ordered snapshot for bootstrap/resume/leaf replacement.
     *
     * @return list<TranscriptBlock>
     */
    public function blocks(): array;

    /**
     * Drain ordinary dirty changes since the previous drain without re-materializing
     * finalized history. Always incremental: live TUI state merges these deltas
     * into an existing snapshot (resume/leaf). Callers that need a full ordered
     * list use {@see blocks()} and build {@see TranscriptChangeSet::full()} themselves.
     */
    public function drainChanges(): TranscriptChangeSet;

    public function reset(): void;
}
