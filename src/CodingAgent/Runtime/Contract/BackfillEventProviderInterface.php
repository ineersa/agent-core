<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\Contract;

use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEvent;

/**
 * Provides backfilled RuntimeEvents from durable storage for run IDs that are
 * not currently streaming events through the live AgentSessionClient pipe.
 *
 * This bridges the gap between the stdout event streaming architecture and
 * the need to resolve stored child-run events on session resume.  The live
 * streaming path (ConsumerStdoutPoller → controller stdout → TUI) covers
 * newly committed events during an active session.  After resume, stored
 * child events exist on disk (artifacts/agents/.../events.jsonl) but never
 * flowed through stdout — this provider backfills them.
 *
 * Callers dedupe by event seq.  Only the selected child live-view poller
 * should read stored child events; background catalog polling must not use this.
 */
interface BackfillEventProviderInterface
{
    /**
     * Retrieve stored RuntimeEvents for a given run ID from durable storage.
     *
     * Returns an empty array when the run is unknown.  The returned events have
     * seq > 0 and are ordered by seq.  Repeated calls may return newly appended
     * stored events; consumers must skip seq <= their cursor.
     *
     * @return list<RuntimeEvent>
     */
    public function getStoredEvents(string $runId): array;
}
