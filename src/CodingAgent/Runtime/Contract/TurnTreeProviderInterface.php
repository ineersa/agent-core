<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\Contract;

use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEvent;
use Ineersa\CodingAgent\Runtime\Protocol\TurnTreeView;

/**
 * Provides a turn tree view for a given session/run.
 *
 * The provider reads canonical events.jsonl and builds a TurnTreeView
 * from the turn tree read model on every call — no caching.
 */
interface TurnTreeProviderInterface
{
    /**
     * Build a turn tree view for the given session/run ID.
     *
     * @return TurnTreeView Never null. An empty event stream produces a tree
     *                      with no nodes.
     */
    public function forSession(string $runId): TurnTreeView;

    /**
     * Return runtime events for the active path leading to a given leaf turn.
     *
     * Uses TurnTreeReplayFilter to filter canonical events to only those on the
     * root-to-target-leaf path, then maps RunEvents to Protocol RuntimeEvents.
     *
     * Returns an empty list when events.jsonl is empty or the target turn
     * does not exist in the turn tree. Never throws for missing data.
     *
     * @return list<RuntimeEvent>
     */
    public function activePathRuntimeEvents(string $runId, int $leafTurnNo): array;
}
