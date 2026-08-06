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
 * Rebuilds on every call — no caching. Maps sparse human prompts only.
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
        $dto = $this->projector->build($events);

        $prompts = [];
        foreach ($dto->promptsByTurnNo as $turnNo => $promptText) {
            $prompts[] = new HistoryPromptView(
                turnNo: (int) $turnNo,
                promptText: $promptText,
            );
        }

        return new HistoryView(
            prompts: $prompts,
            positionTurnNo: $dto->positionTurnNo,
        );
    }
}
