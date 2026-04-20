<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Application\Handler;

use Ineersa\AgentCore\Contract\OutboxStoreInterface;
use Ineersa\AgentCore\Domain\Event\OutboxSink;
use Ineersa\AgentCore\Domain\Message\ProjectJsonlOutbox;
use Ineersa\AgentCore\Infrastructure\Storage\RunLogWriter;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * The JsonlOutboxProjectorWorker consumes ProjectJsonlOutbox messages to persist event data into a JSONL outbox store. It coordinates the projection of domain events by writing to the outbox and logging execution details via a run log writer.
 */
#[AsMessageHandler(bus: 'agent.publisher.bus')]
final readonly class JsonlOutboxProjectorWorker
{
    public function __construct(
        private OutboxStoreInterface $outboxStore,
        private RunLogWriter $runLogWriter,
    ) {
    }

    public function __invoke(ProjectJsonlOutbox $message): void
    {
        $batchSize = max(1, $message->batchSize);
        $now = new \DateTimeImmutable();

        foreach ($this->outboxStore->claim(OutboxSink::Jsonl, $batchSize, $now) as $entry) {
            if (OutboxSink::Jsonl !== $entry->sink || $entry->availableAt > $now) {
                continue;
            }

            $retryDelay = max(1, $message->retryDelaySeconds + $entry->attempts - 1);

            try {
                $this->runLogWriter->append($entry->event);
                $this->outboxStore->markProcessed($entry->id, $now);
            } catch (\Throwable) {
                $this->outboxStore->markFailed($entry->id, $retryDelay, $now);
            }
        }
    }
}
