<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Contract\TurnTree;

/**
 * Read-only turn tree snapshot for rewind validation and branch replay.
 *
 * Interim Core contract surface (SESSION-07A). Tree projection lives in
 * CodingAgent session layer; Core handlers depend on this shape only.
 */
final readonly class TurnTreeSnapshotDTO
{
    /**
     * @param array<int, TurnTreeNodeSnapshotDTO> $nodesByTurnNo     Turn number → node map
     */
    public function __construct(
        public string $runId,
        public array $nodesByTurnNo,
        public ?int $currentLeafTurnNo,
    ) {
    }
}
