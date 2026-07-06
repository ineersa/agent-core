<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Contract\TurnTree;

/**
 * Minimal turn node in a {@see TurnTreeSnapshotDTO} for Core rewind validation.
 *
 * Core handlers only need parent linkage to validate rewind targets (SESSION-07A).
 */
final readonly class TurnTreeNodeSnapshotDTO
{
    public function __construct(
        public int $turnNo,
        public ?int $parentTurnNo,
    ) {
    }
}
