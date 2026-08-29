<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Session;

use Ineersa\AgentCore\Contract\EventStoreInterface;
use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Domain\Message\InvalidateRunContext;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Appends parent-session canonical events, then asks the sole run_control
 * process to discard its cached state. A dispatch failure propagates after
 * the canonical append remains durable.
 */
final readonly class CommittedRunEventAppender
{
    public function __construct(
        private EventStoreInterface $eventStore,
        private MessageBusInterface $commandBus,
    ) {
    }

    public function append(RunEvent $event): RunEvent
    {
        $persisted = $this->eventStore->append($event);
        $this->commandBus->dispatch(new InvalidateRunContext($persisted->runId));

        return $persisted;
    }

    /**
     * @param list<RunEvent> $events
     *
     * @return list<RunEvent>
     */
    public function appendMany(array $events): array
    {
        if ([] === $events) {
            return [];
        }

        $persisted = $this->eventStore->appendMany($events);
        $last = $persisted[array_key_last($persisted)];
        $this->commandBus->dispatch(new InvalidateRunContext($last->runId));

        return $persisted;
    }
}
