<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\Protocol;

/**
 * AgentCore-free view of a session linear history for TUI presentation.
 *
 * Mirrors {@see \Ineersa\CodingAgent\Session\TurnTree\TurnTreeDTO} but lives in
 * Runtime/Protocol so the TUI layer never imports AgentCore types.
 *
 * @see TurnTreeNodeView for individual node shape
 */
final readonly class TurnTreeView
{
    /**
     * @param array<int, TurnTreeNodeView> $nodesByTurnNo     Active turns only
     * @param list<int>                    $rootTurnNos       First active turn
     * @param list<int>                    $activePathTurnNos Full active linear order
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
