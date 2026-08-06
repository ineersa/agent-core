<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Session\TurnTree;

/**
 * Read-only linear history for a single session/run.
 *
 * Built from the canonical event stream by {@see TurnTreeProjector}.
 * Provides the data model for active-history replay filtering and /history UI.
 */
final readonly class TurnTreeDTO
{
    /**
     * @param array<int, TurnTreeNodeDTO> $nodesByTurnNo     Turn number → node map (active turns only)
     * @param list<int>                   $rootTurnNos       First active turn (0 or 1 entry)
     * @param list<int>                   $activePathTurnNos Full active linear history order
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
