<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\Protocol;

/**
 * AgentCore-free view of a single turn for TUI presentation.
 *
 * Mirrors {@see \Ineersa\CodingAgent\Session\TurnTree\TurnTreeNodeDTO} but lives
 * in Runtime/Protocol so the TUI layer never imports AgentCore types.
 *
 * @see TurnTreeView for the linear history container
 */
final readonly class TurnTreeNodeView
{
    /**
     * @param list<int> $childTurnNos Linear next turn only
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
        public string $displayRole = 'assistant',
        public string $fullPromptText = '',
    ) {
    }
}
