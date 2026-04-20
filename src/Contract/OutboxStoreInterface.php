<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Contract;

use Ineersa\AgentCore\Domain\Event\OutboxEntry;
use Ineersa\AgentCore\Domain\Event\OutboxSink;
use Ineersa\AgentCore\Domain\Event\RunEvent;

/**
 * Defines the contract for persisting and retrieving outbound event entries to ensure reliable delivery. It provides mechanisms to enqueue new events, claim batches for processing, and update their status upon completion or failure.
 */
interface OutboxStoreInterface
{
    public function enqueue(RunEvent $event, OutboxSink $sink): void;

    /**
     * retrieves up to limit unprocessed entries for the given sink.
     *
     * @return list<OutboxEntry>
     */
    public function claim(OutboxSink $sink, int $limit = 100, ?\DateTimeImmutable $now = null): array;

    public function markProcessed(int $entryId, ?\DateTimeImmutable $processedAt = null): void;

    public function markFailed(int $entryId, int $retryAfterSeconds = 30, ?\DateTimeImmutable $now = null): void;
}
