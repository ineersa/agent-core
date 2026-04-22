<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Contract;

use Ineersa\AgentCore\Domain\Event\RunEvent;

/**
 * Persists and retrieves execution events scoped to individual runs.
 */
interface EventStoreInterface
{
    public function append(RunEvent $event): void;

    /**
     * Persists an array of RunEvents to the store.
     *
     * @param list<RunEvent> $events
     */
    public function appendMany(array $events): void;

    /**
     * Retrieves all events associated with a specific run ID.
     *
     * @return list<RunEvent>
     */
    public function allFor(string $runId): array;
}
