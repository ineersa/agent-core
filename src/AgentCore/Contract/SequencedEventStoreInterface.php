<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Contract;

use Ineersa\AgentCore\Domain\Event\RunEvent;

/**
 * Event store that allocates the next sequence number atomically with append.
 */
interface SequencedEventStoreInterface extends EventStoreInterface
{
    public function appendWithNextSeq(RunEvent $event): RunEvent;

    /**
     * @param list<RunEvent> $events
     *
     * @return list<RunEvent>
     */
    public function appendManyWithNextSeq(array $events): array;
}
