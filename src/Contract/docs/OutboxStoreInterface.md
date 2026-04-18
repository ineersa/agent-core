# OutboxStoreInterface

**File:** `OutboxStoreInterface.php`  
**Namespace:** `Ineersa\AgentCore\Contract`

## Purpose

Outbox pattern store for reliable event projection. Events are enqueued to named sinks, claimed by workers, and processed/retried.

## Methods

| Method | Signature | Description |
|--------|-----------|-------------|
| `enqueue` | `enqueue(RunEvent $event, OutboxSink $sink): void` | Enqueue an event for a specific sink (mercure, jsonl, etc.). |
| `claim` | `claim(OutboxSink $sink, int $limit = 100, ?DateTimeImmutable $now = null): list<OutboxEntry>` | Claim pending entries for processing. |
| `markProcessed` | `markProcessed(int $entryId, ?DateTimeImmutable $processedAt = null): void` | Mark entry as successfully projected. |
| `markFailed` | `markFailed(int $entryId, int $retryAfterSeconds = 30, ?DateTimeImmutable $now = null): void` | Mark entry as failed, schedule retry. |

## Dependencies

- `Domain\Event\OutboxEntry`
- `Domain\Event\OutboxSink`
- `Domain\Event\RunEvent`
