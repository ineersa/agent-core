<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Application\Handler;

use Ineersa\AgentCore\Contract\OutboxStoreInterface;
use Ineersa\AgentCore\Domain\Event\OutboxSink;
use Ineersa\AgentCore\Domain\Message\ProjectMercureOutbox;
use Ineersa\AgentCore\Infrastructure\Mercure\RunEventPublisher;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * This class acts as a worker that processes Mercure outbox projection messages by retrieving pending events from the outbox store. It publishes these events via the run event publisher to ensure consistent delivery to Mercure subscribers.
 */
#[AsMessageHandler(bus: 'agent.publisher.bus')]
final readonly class MercureOutboxProjectorWorker
{
    public function __construct(
        private OutboxStoreInterface $outboxStore,
        private RunEventPublisher $runEventPublisher,
    ) {
    }

    public function __invoke(ProjectMercureOutbox $message): void
    {
        $batchSize = max(1, $message->batchSize);
        $now = new \DateTimeImmutable();

        foreach ($this->outboxStore->claim(OutboxSink::Mercure, $batchSize, $now) as $entry) {
            if (OutboxSink::Mercure !== $entry->sink || $entry->availableAt > $now) {
                continue;
            }

            $retryDelay = max(1, $message->retryDelaySeconds + $entry->attempts - 1);

            try {
                $this->runEventPublisher->publish($entry->event);
                $this->outboxStore->markProcessed($entry->id, $now);
            } catch (\Throwable) {
                $this->outboxStore->markFailed($entry->id, $retryDelay, $now);
            }
        }
    }
}
