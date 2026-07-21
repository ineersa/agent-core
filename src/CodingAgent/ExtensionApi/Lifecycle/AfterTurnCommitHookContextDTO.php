<?php

declare(strict_types=1);

namespace Ineersa\Hatfield\ExtensionApi\Lifecycle;

use Ineersa\Hatfield\ExtensionApi\Session\SessionEventDTO;

/**
 * Public post-commit hook context with the just-persisted canonical event batch.
 *
 * Events are complete SessionEventDTO values (payload, turnNo, createdAt) derived
 * from the hot commit context — no events.jsonl reread.
 */
final readonly class AfterTurnCommitHookContextDTO
{
    /** @var list<SessionEventDTO> */
    public array $events;

    /**
     * @param list<SessionEventDTO> $events
     */
    public function __construct(
        public string $runId,
        public int $turnNo,
        public string $status,
        array $events,
        public int $effectsCount,
    ) {
        foreach ($events as $event) {
            if (!$event instanceof SessionEventDTO) {
                throw new \InvalidArgumentException('events must be a list of SessionEventDTO.');
            }
        }

        $this->events = array_values($events);
    }
}
