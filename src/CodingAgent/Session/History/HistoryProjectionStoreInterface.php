<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Session\History;

use Ineersa\AgentCore\Domain\Event\RunEvent;

/**
 * Shared disposable retained-history projection for one run.
 *
 * Ordinary get/applyCommitted paths never scan the archive. Freshness is the
 * committed sequence identity on the cached snapshot.
 */
interface HistoryProjectionStoreInterface
{
    public function get(string $runId): HistoryProjectionSnapshot;

    public function find(string $runId): ?HistoryProjectionSnapshot;

    public function remember(string $runId, HistoryProjectionSnapshot $snapshot): void;

    /**
     * Mark the current projection not-ready before a canonical append that may be
     * interrupted. Ordinary get() rejects not-ready snapshots. Missing entries are a no-op.
     */
    public function withdrawForCommit(string $runId): void;

    /**
     * Cold startup/recovery publish from an ordered canonical event stream.
     * Validates duplicate sequences. Does not read the archive itself.
     *
     * @param iterable<RunEvent> $events
     */
    public function initializeFromEvents(string $runId, iterable $events): HistoryProjectionSnapshot;

    /**
     * Apply already-committed events onto the shared projection without scanning the archive.
     *
     * New runs may bootstrap from a committed batch that includes RunStarted.
     * Cache misses fail closed as a recovery requirement. Sequence holes are valid.
     *
     * @param list<RunEvent> $events
     */
    public function applyCommitted(string $runId, array $events): void;
}
