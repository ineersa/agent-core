<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Application\Handler;

use Ineersa\AgentCore\Contract\OutboxStoreInterface;
use Ineersa\AgentCore\Domain\Event\OutboxSink;
use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Domain\Message\ProjectJsonlOutbox;
use Ineersa\AgentCore\Domain\Message\ProjectMercureOutbox;
use Ineersa\AgentCore\Infrastructure\Mercure\RunEventPublisher;
use Ineersa\AgentCore\Infrastructure\Storage\RunLogWriter;

/**
 * The OutboxProjector class processes a batch of domain events by persisting execution logs and publishing corresponding runtime events. It acts as a bridge between the outbox storage mechanism and external consumers via the event publisher.
 */
final readonly class OutboxProjector
{
    /**
     * initializes the projector with required outbox, logging, and publishing collaborators.
     */
    public function __construct(
        private OutboxStoreInterface $outboxStore,
        private RunLogWriter $runLogWriter,
        private RunEventPublisher $runEventPublisher,
    ) {
    }

    /**
     * processes an array of events by writing run logs and publishing run events.
     *
     * @param list<RunEvent> $events
     */
    public function project(array $events): void
    {
        foreach ($events as $event) {
            $this->outboxStore->enqueue($event, OutboxSink::Jsonl);
            $this->outboxStore->enqueue($event, OutboxSink::Mercure);
        }

        $jsonlWorker = new JsonlOutboxProjectorWorker($this->outboxStore, $this->runLogWriter);
        $mercureWorker = new MercureOutboxProjectorWorker($this->outboxStore, $this->runEventPublisher);

        $jsonlWorker(new ProjectJsonlOutbox());
        $mercureWorker(new ProjectMercureOutbox());
    }
}
