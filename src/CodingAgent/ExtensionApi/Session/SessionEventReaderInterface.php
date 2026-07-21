<?php

declare(strict_types=1);

namespace Ineersa\Hatfield\ExtensionApi\Session;

/**
 * Read-only access to the canonical append-only session event stream.
 *
 * Returns public DTOs only. No RunEvent, EventStore, turn-tree, session path,
 * or runtime mutation objects are exposed. MVP reads are non-branch-aware and
 * include abandoned rewind activity present in the canonical stream.
 */
interface SessionEventReaderInterface
{
    /**
     * Read canonical events for a run in the inclusive sequence range.
     *
     * @return list<SessionEventDTO>
     *
     * @throws SessionEventReaderException when the session/run is missing or the range is invalid
     */
    public function readRange(string $runId, int $startSeq, int $endSeq): array;
}
