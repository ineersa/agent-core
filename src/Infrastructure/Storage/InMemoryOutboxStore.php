<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Infrastructure\Storage;

use Ineersa\AgentCore\Contract\OutboxStoreInterface;
use Ineersa\AgentCore\Domain\Event\OutboxEntry;
use Ineersa\AgentCore\Domain\Event\OutboxSink;
use Ineersa\AgentCore\Domain\Event\RunEvent;

/**
 * InMemoryOutboxStore provides an in-memory implementation of the outbox pattern for buffering domain events before publication. It ensures event durability within the current process lifecycle by managing entry states and preventing duplicate submissions via sink-based deduplication.
 */
final class InMemoryOutboxStore implements OutboxStoreInterface
{
    /**
     * @var array<int, array{
     *   id: int,
     *   sink: OutboxSink,
     *   event: RunEvent,
     *   status: 'pending'|'processing'|'processed'|'failed',
     *   attempts: int,
     *   available_at: \DateTimeImmutable,
     *   processed_at: \DateTimeImmutable|null
     * }>
     */
    private array $entries = [];

    /** @var array<string, int> */
    private array $dedupe = [];

    private int $nextId = 1;

    /**
     * Adds a RunEvent to the outbox buffer using the OutboxSink for deduplication.
     */
    public function enqueue(RunEvent $event, OutboxSink $sink): void
    {
        $key = $this->dedupeKey($sink, $event);
        if (isset($this->dedupe[$key])) {
            return;
        }

        $id = $this->nextId++;
        $this->entries[$id] = [
            'id' => $id,
            'sink' => $sink,
            'event' => $event,
            'status' => 'pending',
            'attempts' => 0,
            'available_at' => new \DateTimeImmutable(),
            'processed_at' => null,
        ];
        $this->dedupe[$key] = $id;
    }

    /**
     * Retrieves up to limit pending events for processing with optional timestamp override.
     */
    public function claim(OutboxSink $sink, int $limit = 100, ?\DateTimeImmutable $now = null): array
    {
        $clock = $now ?? new \DateTimeImmutable();
        $batchSize = max(1, $limit);

        $claimed = [];
        foreach ($this->entries as $id => $entry) {
            if ($entry['sink'] !== $sink) {
                continue;
            }

            if (!\in_array($entry['status'], ['pending', 'failed'], true)) {
                continue;
            }

            if ($entry['available_at'] > $clock) {
                continue;
            }

            $attempts = $entry['attempts'] + 1;
            $this->entries[$id]['attempts'] = $attempts;
            $this->entries[$id]['status'] = 'processing';

            $claimed[] = new OutboxEntry(
                id: $entry['id'],
                sink: $entry['sink'],
                event: $entry['event'],
                attempts: $attempts,
                availableAt: $entry['available_at'],
            );

            if (\count($claimed) >= $batchSize) {
                break;
            }
        }

        return $claimed;
    }

    /**
     * Marks a specific outbox entry as successfully processed with optional timestamp.
     */
    public function markProcessed(int $entryId, ?\DateTimeImmutable $processedAt = null): void
    {
        if (!isset($this->entries[$entryId])) {
            return;
        }

        $this->entries[$entryId]['status'] = 'processed';
        $this->entries[$entryId]['processed_at'] = $processedAt ?? new \DateTimeImmutable();
    }

    /**
     * Marks a specific outbox entry as failed with retry delay and optional timestamp.
     */
    public function markFailed(int $entryId, int $retryAfterSeconds = 30, ?\DateTimeImmutable $now = null): void
    {
        if (!isset($this->entries[$entryId])) {
            return;
        }

        $delay = max(1, $retryAfterSeconds);
        $clock = $now ?? new \DateTimeImmutable();

        $this->entries[$entryId]['status'] = 'failed';
        $this->entries[$entryId]['available_at'] = $clock->setTimestamp($clock->getTimestamp() + $delay);
    }

    /**
     * Generates a unique string identifier for an event and sink combination.
     */
    private function dedupeKey(OutboxSink $sink, RunEvent $event): string
    {
        return \sprintf('%s|%s|%d', $sink->value, $event->runId, $event->seq);
    }
}
