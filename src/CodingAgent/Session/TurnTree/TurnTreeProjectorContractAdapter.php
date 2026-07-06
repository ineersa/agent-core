<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Session\TurnTree;

use Ineersa\AgentCore\Contract\TurnTree\TurnTreeNodeSnapshotDTO;
use Ineersa\AgentCore\Contract\TurnTree\TurnTreeProjectorInterface;
use Ineersa\AgentCore\Contract\TurnTree\TurnTreeSnapshotDTO;

/**
 * Bridges session turn-tree projection to Core contract (SESSION-07A).
 */
final readonly class TurnTreeProjectorContractAdapter implements TurnTreeProjectorInterface
{
    public function __construct(
        private TurnTreeProjector $projector,
    ) {
    }

    public function build(string $runId, array $events): TurnTreeSnapshotDTO
    {
        $tree = $this->projector->build($runId, $events);

        $nodes = [];
        foreach ($tree->nodesByTurnNo as $turnNo => $node) {
            $nodes[$turnNo] = new TurnTreeNodeSnapshotDTO(
                turnNo: $node->turnNo,
                parentTurnNo: $node->parentTurnNo,
                childTurnNos: $node->childTurnNos,
                anchorSeq: $node->anchorSeq,
                lastSeq: $node->lastSeq,
                title: $node->title,
                promptPreview: $node->promptPreview,
                createdAt: $node->createdAt,
                isCurrentLeaf: $node->isCurrentLeaf,
                reason: $node->reason,
                displayRole: $node->displayRole,
            );
        }

        return new TurnTreeSnapshotDTO(
            runId: $tree->runId,
            nodesByTurnNo: $nodes,
            rootTurnNos: $tree->rootTurnNos,
            currentLeafTurnNo: $tree->currentLeafTurnNo,
            activePathTurnNos: $tree->activePathTurnNos,
        );
    }
}
