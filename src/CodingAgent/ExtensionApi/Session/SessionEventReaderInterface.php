<?php

declare(strict_types=1);

namespace Ineersa\Hatfield\ExtensionApi\Session;

/**
 * Recovery/catch-up/compaction access to the canonical append-only session event stream.
 *
 * Returns public DTOs only. No RunEvent, EventStore, turn-tree, session path,
 * or runtime mutation objects are exposed. MVP reads are non-branch-aware and
 * include abandoned rewind activity present in the canonical stream.
 *
 * This is intentionally NOT a per-boundary hot-path reader. Hot commit hooks
 * already expose just-persisted SessionEventDTO batches. The MVP implementation
 * may scan the full journal; that cost is acceptable only for recovery or
 * compaction catch-up, not every turn.
 */
interface SessionEventReaderInterface
{
    /**
     * Read canonical events for a run in the inclusive sequence range.
     *
     * Recovery/compaction use only. Prefer post-commit hot batches for ongoing
     * observation pipelines.
     *
     * @return list<SessionEventDTO>
     *
     * @throws SessionEventReaderException when the session/run is missing or the range is invalid
     */
    public function readRange(string $runId, int $startSeq, int $endSeq): array;
}
