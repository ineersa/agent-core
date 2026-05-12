<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Application\Handler;

use Ineersa\AgentCore\Contract\OutboxProjectorInterface;
use Ineersa\AgentCore\Contract\OutboxStoreInterface;
use Ineersa\AgentCore\Domain\Event\OutboxSink;
use Ineersa\AgentCore\Domain\Event\RunEvent;

final readonly class OutboxProjector
{
    /**
     * @param iterable<OutboxProjectorInterface> $projectors
     */
    public function __construct(
        private OutboxStoreInterface $outboxStore,
        private iterable $projectors,
    ) {
    }

    /**
     * Enqueues events into each registered sink and processes them.
     *
     * @param list<RunEvent> $events
     */
    public function project(array $events): void
    {
        /** @var array<OutboxSink, OutboxProjectorInterface> $sinkMap */
        $sinkMap = [];
        foreach ($this->projectors as $projector) {
            $sinkMap[$projector->sink()->value] = $projector;
        }

        foreach ($sinkMap as $sink => $projector) {
            foreach ($events as $event) {
                $this->outboxStore->enqueue($event, $projector->sink());
            }

            $projector->processBatch();
        }
    }
}
