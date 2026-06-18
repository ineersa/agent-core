<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Domain\Extension;

use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Domain\Run\RunState;
use Symfony\Component\Serializer\Attribute\SerializedName;

final readonly class AfterTurnCommitHookContext
{
    /** @var list<AfterTurnCommitEventSummary> */
    public array $events;

    /**
     * @param list<AfterTurnCommitEventSummary> $events
     */
    public function __construct(
        #[SerializedName('run_id')]
        public string $runId,
        #[SerializedName('turn_no')]
        public int $turnNo,
        public string $status,
        array $events,
        #[SerializedName('effects_count')]
        public int $effectsCount,
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
     */
    public static function fromRunState(RunState $runState, array $events, int $effectsCount): self
    {
        return new self(
            runId: $runState->runId,
            turnNo: $runState->turnNo,
            status: $runState->status->value,
            events: array_values(array_map(
                static fn (RunEvent $event): AfterTurnCommitEventSummary => new AfterTurnCommitEventSummary(
                    seq: $event->seq,
                    type: $event->type,
                ),
                $events,
            )),
            effectsCount: $effectsCount,
        );
    }
}
