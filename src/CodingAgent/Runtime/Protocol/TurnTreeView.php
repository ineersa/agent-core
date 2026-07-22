<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\Protocol;

/**
 * AgentCore-free view of a session turn tree for TUI presentation.
 *
 * Mirrors {@see \Ineersa\CodingAgent\Session\TurnTree\TurnTreeDTO} but lives in
 * Runtime/Protocol so the TUI layer never imports AgentCore types.
 *
 * @see TurnTreeNodeView for individual node shape
 */
final readonly class TurnTreeView
{
    /**
     * @param array<int, TurnTreeNodeView> $nodesByTurnNo     Turn number → node map
     * @param list<int>                    $rootTurnNos       Turn numbers with no parent
     * @param list<int>                    $activePathTurnNos Root-to-leaf path
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
