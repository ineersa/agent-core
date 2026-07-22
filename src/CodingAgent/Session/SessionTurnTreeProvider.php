<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Session;

use Ineersa\AgentCore\Contract\EventStoreInterface;
use Ineersa\CodingAgent\Runtime\Contract\TurnTreeProviderInterface;
use Ineersa\CodingAgent\Runtime\Protocol\TurnTreeNodeView;
use Ineersa\CodingAgent\Runtime\Protocol\TurnTreeView;
use Ineersa\CodingAgent\Session\TurnTree\TurnTreeProjector;

/**
 * Session-backed TurnTreeProviderInterface.
 *
 * Reads canonical events.jsonl via SessionRunEventStore, projects the turn
 * tree via TurnTreeProjector, and maps to AgentCore-free Protocol DTOs.
 *
 * Rebuilds on every call — no caching.
 */
final readonly class SessionTurnTreeProvider implements TurnTreeProviderInterface
{
    public function __construct(
        private EventStoreInterface $eventStore,
        private TurnTreeProjector $projector,
    ) {
    }

    public function forSession(string $runId): TurnTreeView
    {
        $events = $this->eventStore->allFor($runId);
        $dto = $this->projector->build($runId, $events);

        $nodeViews = [];
        foreach ($dto->nodesByTurnNo as $turnNo => $node) {
            $nodeViews[$turnNo] = new TurnTreeNodeView(
                turnNo: $node->turnNo,
                parentTurnNo: $node->parentTurnNo,
                childTurnNos: $node->childTurnNos,
                anchorSeq: $node->anchorSeq,
                title: $node->title,
                promptPreview: $node->promptPreview,
                createdAt: $node->createdAt,
                isCurrentLeaf: $node->isCurrentLeaf,
                displayRole: $node->displayRole,
            );
        }

        return new TurnTreeView(
            runId: $dto->runId,
            nodesByTurnNo: $nodeViews,
            rootTurnNos: $dto->rootTurnNos,
            currentLeafTurnNo: $dto->currentLeafTurnNo,
            activePathTurnNos: $dto->activePathTurnNos,
        );
    }
}
