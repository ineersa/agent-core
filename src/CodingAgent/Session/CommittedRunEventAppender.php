<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Session;

use Ineersa\AgentCore\Application\Handler\RunLockManager;
use Ineersa\AgentCore\Application\Replay\RunStateReducer;
use Ineersa\AgentCore\Contract\ActiveRunContextInterface;
use Ineersa\AgentCore\Contract\EventStoreInterface;
use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Domain\Message\InvalidateRunContext;
use Ineersa\CodingAgent\Session\History\HistoryProjectionStoreInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Appends parent-session canonical events, advances shared projections from the
 * committed suffix, then asks run_control to drop its process-local hot cache.
 * A maintenance or dispatch failure propagates after the canonical append remains durable.
 */
final readonly class CommittedRunEventAppender
{
    public function __construct(
        private EventStoreInterface $eventStore,
        private MessageBusInterface $commandBus,
        private ActiveRunContextInterface $activeRunContext,
        private RunStateReducer $runStateReducer,
        private RunLockManager $runLockManager,
        private ?HistoryProjectionStoreInterface $historyProjectionStore = null,
    ) {
    }

    public function append(RunEvent $event): RunEvent
    {
        return $this->runLockManager->synchronized($event->runId, function () use ($event): RunEvent {
            $this->activeRunContext->withdrawForCommit($event->runId);
            $persisted = $this->eventStore->append($event);
            $this->maintainAfterCommit($persisted->runId, [$persisted]);

            return $persisted;
        });
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

        return $this->runLockManager->synchronized($events[0]->runId, function () use ($events): array {
            $this->activeRunContext->withdrawForCommit($events[0]->runId);
            $persisted = $this->eventStore->appendMany($events);
            $last = $persisted[array_key_last($persisted)];
            $this->maintainAfterCommit($last->runId, $persisted);

            return $persisted;
        });
    }

    /**
     * @param list<RunEvent> $persisted
     */
    private function maintainAfterCommit(string $runId, array $persisted): void
    {
        // Both projections still describe the pre-append cursor here. Advance
        // state before metadata; readers cannot observe the intermediate pair
        // because the entire append/publication holds the transition lock.
        $this->activeRunContext->applyCommittedSuffix(
            $runId,
            $persisted,
            $this->runStateReducer->applyCommittedSuffix(...),
        );
        $this->historyProjectionStore?->applyCommitted($runId, $persisted);

        $this->commandBus->dispatch(new InvalidateRunContext($runId));
    }
}
