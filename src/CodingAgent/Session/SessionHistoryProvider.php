<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Session;

use Ineersa\AgentCore\Contract\EventStoreInterface;
use Ineersa\CodingAgent\Runtime\Contract\HistoryProviderInterface;
use Ineersa\CodingAgent\Runtime\Protocol\HistoryPromptView;
use Ineersa\CodingAgent\Runtime\Protocol\HistoryView;
use Ineersa\CodingAgent\Session\History\HistoryProjector;

/**
 * Session-backed HistoryProviderInterface.
 *
 * Rebuilds on every call — no caching.
 */
final readonly class SessionHistoryProvider implements HistoryProviderInterface
{
    public function __construct(
        private EventStoreInterface $eventStore,
        private HistoryProjector $projector,
    ) {
    }

    public function forSession(string $runId): HistoryView
    {
        $events = $this->eventStore->allFor($runId);
        $dto = $this->projector->build($runId, $events);

        // Public picker contract: user prompts only (assistant/tool turns stay internal).
        $turns = [];
        foreach ($dto->turns as $turn) {
            if ('user' !== $turn->displayRole) {
                continue;
            }
            $turns[] = new HistoryPromptView(
                turnNo: $turn->turnNo,
                title: $turn->title,
                displayRole: $turn->displayRole,
                promptText: $turn->promptText,
                isPosition: $turn->turnNo === $dto->positionTurnNo,
            );
        }

        return new HistoryView(
            runId: $dto->runId,
            turns: $turns,
            positionTurnNo: $dto->positionTurnNo,
        );
    }
}
