<?php

declare(strict_types=1);

namespace Ineersa\Hatfield\ExtensionApi\Lifecycle;

final readonly class AfterTurnCommitHookContextDTO
{
    /** @var list<AfterTurnCommitEventSummaryDTO> */
    public array $events;

    /**
     * @param list<AfterTurnCommitEventSummaryDTO> $events
     */
    public function __construct(
        public string $runId,
        public int $turnNo,
        public string $status,
        array $events,
        public int $effectsCount,
    ) {
        foreach ($events as $event) {
            if (!$event instanceof AfterTurnCommitEventSummaryDTO) {
                throw new \InvalidArgumentException('events must be a list of AfterTurnCommitEventSummaryDTO.');
            }
        }

        $this->events = array_values($events);
    }
}
