<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Session;

use Ineersa\CodingAgent\Runtime\Contract\HistoryProviderInterface;
use Ineersa\CodingAgent\Runtime\Protocol\HistoryPromptView;
use Ineersa\CodingAgent\Runtime\Protocol\HistoryView;
use Ineersa\CodingAgent\Session\History\HistoryDTO;
use Ineersa\CodingAgent\Session\History\HistoryProjectionStoreInterface;

/**
 * Session-backed HistoryProviderInterface.
 *
 * Ordinary lookups use the shared history projection store published by cold
 * reconstruction or committed updates. No archive or event-list rebuild path.
 */
final readonly class SessionHistoryProvider implements HistoryProviderInterface
{
    public function __construct(
        private HistoryProjectionStoreInterface $historyProjectionStore,
    ) {
    }

    public function forSession(string $runId): HistoryView
    {
        return $this->toView($this->historyProjectionStore->get($runId)->history);
    }

    private function toView(HistoryDTO $dto): HistoryView
    {
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
