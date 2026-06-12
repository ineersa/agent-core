<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Domain\Run;

/**
 * Read-only representation of a single turn node in the session turn tree.
 *
 * Each turn_advanced event in the canonical event stream produces one node.
 * Nodes are linked via parentTurnNo / childTurnNos to form the tree.
 */
final readonly class TurnTreeNodeDTO
{
    /**
     * @param list<int> $childTurnNos
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
