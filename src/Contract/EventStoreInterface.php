<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Contract;

use Ineersa\AgentCore\Domain\Event\RunEvent;

interface EventStoreInterface
{
    public function append(RunEvent $event): void;

    /**
     * @param list<RunEvent> $events
     */
    public function appendMany(array $events): void;

    /**
     * @return list<RunEvent>
     */
    public function allFor(string $runId): array;
}
