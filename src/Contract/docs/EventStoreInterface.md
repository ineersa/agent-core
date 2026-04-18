# EventStoreInterface

**File:** `EventStoreInterface.php`  
**Namespace:** `Ineersa\AgentCore\Contract`

## Purpose

Event sourcing store for `RunEvent`. Append-only with run-scoped retrieval.

## Methods

| Method | Signature | Description |
|--------|-----------|-------------|
| `append` | `append(RunEvent $event): void` | Append a single event. |
| `appendMany` | `appendMany(array $events): void` | Batch append events atomically. |
| `allFor` | `allFor(string $runId): list<RunEvent>` | Load all events for a given run. |

## Dependencies

- `Domain\Event\RunEvent`
