# Application\Handler

Handler infrastructure — 19 classes covering routing, registries, workers, projectors, and utilities.

## Routing & Registries

| Class | Purpose |
|-------|---------|
| `CommandRouter` | Routes ApplyCommand → core/extension/rejected with prefix and option validation |
| `CommandHandlerRegistry` | Finds `CommandHandlerInterface` by kind from tagged services |
| `EventSubscriberRegistry` | Yields `EventSubscriberInterface` matching event type |
| `HookDispatcher` | Dispatches boundary hooks via Symfony EventDispatcher + subscriber registry |
| `HookSubscriberRegistry` | Yields `HookSubscriberInterface` matching hook name |

## Execution Workers (Messenger handlers)

| Class | Bus | Purpose |
|-------|-----|---------|
| `ExecuteLlmStepWorker` | `agent.execution.bus` | Invokes Platform, normalizes response, dispatches LlmStepResult |
| `ExecuteToolCallWorker` | `agent.execution.bus` | Invokes ToolExecutorInterface, dispatches ToolCallResult |

## Tool System

| Class | Purpose |
|-------|---------|
| `ToolExecutor` | Policy-aware placeholder (stage 00). Resolves mode/timeout per tool. Real impl in stage 06 |
| `ToolCatalogResolver` | Aggregates ToolCatalogProviderInterface, deduplicates, enforces schema stability |
| `ToolBatchCollector` | Collects tool results into ordered batches with dedup |
| `ToolBatchCollectOutcome` | Outcome value object: rejected/duplicate/acceptedPending/acceptedComplete |

## Outbox Projection

| Class | Bus | Purpose |
|-------|-----|---------|
| `OutboxProjector` | — | Enqueues events to JSONL+Mercure sinks, runs both projectors inline |
| `JsonlOutboxProjectorWorker` | `agent.publisher.bus` | Claims JSONL entries, appends to RunLogWriter |
| `MercureOutboxProjectorWorker` | `agent.publisher.bus` | Claims Mercure entries, publishes via RunEventPublisher |

## Infrastructure

| Class | Purpose |
|-------|---------|
| `RunEventDispatcher` | Dispatches RunEvent via Symfony EventDispatcher + subscriber registry |
| `StepDispatcher` | Dispatches effects to execution bus, publishes to publisher bus |
| `RunLockManager` | Per-run mutex via Symfony Lock (synchronized callable wrapper) |
| `MessageIdempotencyService` | In-memory dedup: wasHandled/markHandled scoped by (scope, runId, key) |
| `ReplayService` | Rebuilds hot prompt state from events/JSONL, verifies sequence integrity |
