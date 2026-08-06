<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Session\TurnTree;

/**
 * Read-only representation of a single turn in the session linear history.
 *
 * Each turn_advanced event that remains active produces one node.
 * parentTurnNo / childTurnNos form a pure linear previous/next chain
 * (not a branch tree).
 */
final readonly class TurnTreeNodeDTO
{
    /**
     * @param list<int> $childTurnNos   Linear next turn only (0 or 1 entry)
     * @param int       $lastSeq        max canonical event sequence among events
     *                                  scoped to this turn (RunEvent::$turnNo)
     * @param string    $fullPromptText Original user prompt text for /history editor population
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
        public string $fullPromptText = '',
    ) {
    }
}
