<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\Contract;

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
}
