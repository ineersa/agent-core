<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Domain\Extension;

use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Domain\Run\RunState;

final readonly class AfterTurnCommitHookContext
{
    /** @var list<AfterTurnCommitEventSummary> */
    public array $events;

    /**
     * @param list<AfterTurnCommitEventSummary> $events
     * @param list<object>                      $postCommitActions
     */
    public function __construct(
        public string $runId,
        public int $turnNo,
        public string $status,
        array $events,
        public int $effectsCount,
        public RunState $runState,
        public array $postCommitActions = [],
    ) {
        foreach ($events as $event) {
            if (!$event instanceof AfterTurnCommitEventSummary) {
                throw new \InvalidArgumentException('events must be a list of AfterTurnCommitEventSummary.');
            }
        }

        $this->events = array_values($events);
    }

    /**
     * @param list<RunEvent> $events
     * @param list<object>   $postCommitActions
     */
    public static function fromRunState(RunState $runState, array $events, int $effectsCount, array $postCommitActions = []): self
    {
        return new self(
            runId: $runState->runId,
            turnNo: $runState->turnNo,
            status: $runState->status->value,
            events: array_values(array_map(
                static fn (RunEvent $event): AfterTurnCommitEventSummary => new AfterTurnCommitEventSummary(
                    seq: $event->seq,
                    type: $event->type,
                    payload: $event->payload,
                    turnNo: $event->turnNo,
                    createdAt: $event->createdAt->format(\DateTimeInterface::ATOM),
                ),
                $events,
            )),
            effectsCount: $effectsCount,
            runState: $runState,
            postCommitActions: array_values($postCommitActions),
        );
    }
}
