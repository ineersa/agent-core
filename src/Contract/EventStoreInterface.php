<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Contract;

use Ineersa\AgentCore\Domain\Event\RunEvent;

/**
 * Defines the contract for persisting and retrieving execution events within the Agent Core system. It abstracts the underlying storage mechanism to ensure consistent interaction with run-specific event data. This interface serves as the primary boundary for event persistence operations.
 */
interface EventStoreInterface
{
    /**
     * Persists a single RunEvent to the store.
     */
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
