<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Session\TurnTree;

/**
 * Read-only turn tree for a single session/run.
 *
 * Built from the canonical event stream by {@see TurnTreeProjector}.
 * Provides the data model for branch/replay filtering and future /tree UI rendering.
 */
final readonly class TurnTreeDTO
{
    /**
     * @param array<int, TurnTreeNodeDTO> $nodesByTurnNo     Turn number → node map
     * @param list<int>                   $rootTurnNos       Turn numbers with no parent (root nodes)
     * @param list<int>                   $activePathTurnNos Turn numbers on the path from root to current leaf
     */
    public function __construct(
        public string $runId,
        public array $nodesByTurnNo,
        public array $rootTurnNos,
        public ?int $currentLeafTurnNo,
        public array $activePathTurnNos,
    ) {
    }
}
