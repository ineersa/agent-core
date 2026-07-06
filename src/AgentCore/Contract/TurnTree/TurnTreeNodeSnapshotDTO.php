<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Contract\TurnTree;

/**
 * Read-only turn node in a {@see TurnTreeSnapshotDTO}.
 */
final readonly class TurnTreeNodeSnapshotDTO
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
        public string $displayRole = 'assistant',
    ) {
    }
}
