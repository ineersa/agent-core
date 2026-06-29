<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\Protocol;

/**
 * AgentCore-free view of a single turn node for TUI presentation.
 *
 * Mirrors {@see \Ineersa\AgentCore\Domain\Run\TurnTreeNodeDTO} but lives
 * in Runtime/Protocol so the TUI layer never imports AgentCore types.
 *
 * @see TurnTreeView for the full tree container
 */
final readonly class TurnTreeNodeView
{
    /**
     * @param list<int> $childTurnNos
     */
    public function __construct(
        public int $turnNo,
        public ?int $parentTurnNo,
        public array $childTurnNos,
        public int $anchorSeq,
        public string $title,
        public string $promptPreview,
        public ?\DateTimeImmutable $createdAt,
        public bool $isCurrentLeaf,
    ) {
    }
}
