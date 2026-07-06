<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Session\TurnTree;

/**
 * Read-only representation of a single turn node in the session turn tree.
 *
 * Each turn_advanced event in the canonical event stream produces one node.
 * Nodes are linked via parentTurnNo / childTurnNos to form the tree.
 *
 * {@see $lastSeq} is the maximum canonical event sequence across all
 * events whose {@see RunEvent::$turnNo} belongs to this turn (including
 * turn_advanced, leaf_set, turn_branched, message, and tool events).
 * Abandoned sibling turns do not claim sequences from later active
 * branches. The canonical stream's overall last sequence is tracked
 * separately by {@see RunState} after replay.
 */
final readonly class TurnTreeNodeDTO
{
    /**
     * @param list<int> $childTurnNos
     * @param int       $lastSeq      Max canonical event sequence among all events
     *                                scoped to this turn (grouped by RunEvent::$turnNo).
     *                                Includes turn_advanced, leaf_set, turn_branched,
     *                                message, and tool events belonging to this turn.
     *                                Abandoned sibling branches do not contribute.
     */
    public function __construct(
        public int $turnNo,
        public ?int $parentTurnNo,
        public array $childTurnNos,
        public int $anchorSeq,
        public int $lastSeq,
        public string $title,
        public string $promptPreview,
        public ?\DateTimeImmutable $createdAt,
        public bool $isCurrentLeaf,
        public ?string $reason = null,
        public string $displayRole = 'assistant',
    ) {
    }
}
