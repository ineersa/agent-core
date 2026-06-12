<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Domain\Run;

/**
 * Read-only representation of a single turn node in the session turn tree.
 *
 * Each turn_advanced event in the canonical event stream produces one node.
 * Nodes are linked via parentTurnNo / childTurnNos to form the tree.
 *
 * {@see $lastSeq} is the last event sequence belonging to this turn's event
 * window (the seq just before the next sibling's anchor, or the canonical
 * max seq when this node is the current leaf). After a rewind the current
 * leaf may be an earlier turn, so its {@see $lastSeq} reflects only its
 * own event window, not the full stream max seq from abandoned branches.
 */
final readonly class TurnTreeNodeDTO
{
    /**
     * @param list<int> $childTurnNos
     * @param int       $lastSeq      Last event sequence in this turn's event window.
     *                                For non-current-leaf turns: the sequence just before
     *                                the next turn_advanced anchor. For the current leaf
     *                                node: the canonical max event sequence in the stream.
     *                                After rewind, a non-root current leaf may have
     *                                lastSeq < canonical max seq (abandoned future events).
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
    ) {
    }
}
