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
     * Latest durably appended canonical sequence, or null when the run has no events.
     */
    public function latestSequenceFor(string $runId): ?int;

    /**
     * First canonical event, or null when the run has no events.
     */
    public function firstFor(string $runId): ?RunEvent;

    /**
     * Retrieves all events associated with a specific run ID.
     *
     * @return list<RunEvent>
     */
    public function allFor(string $runId): array;
}
