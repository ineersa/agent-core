<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Contract;

use Ineersa\AgentCore\Domain\Event\RunEvent;

interface EventStoreInterface
{
    public function append(RunEvent $event): RunEvent;

    /**
     * @param list<RunEvent> $events
     *
     * @return list<RunEvent>
     */
    public function appendMany(array $events): array;

    /**
     * Retrieves all events associated with a specific run ID.
     *
     * @return list<RunEvent>
     */
    public function allFor(string $runId): array;
}
