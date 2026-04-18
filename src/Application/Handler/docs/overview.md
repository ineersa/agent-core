# Application\Handler

Handler infrastructure — 21 classes covering routing, registries, workers, projectors, and utilities.

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
| `ExecuteToolCallWorker` | `agent.execution.bus` | Hydrates enriched ToolCall VO (mode/timeout/assistantMessage/argSchema/cancelToken), invokes ToolExecutorInterface, dispatches ToolCallResult |

## Tool Execution Engine (Stage 06)

| Class | Purpose |
|-------|---------|
| `ToolExecutor` | Full execution engine — resolves policy, checks idempotency store, validates args, runs before/after hooks, executes via Symfony Toolbox or interrupt fallback, enforces timeout, stamps results with metadata |
| `ToolExecutionPolicyResolver` | Resolves `ToolExecutionPolicy` per tool name — per-tool overrides on global defaults for mode, timeout, maxParallelism |
| `ToolExecutionResultStore` | In-memory idempotency store — keyed by (runId, toolCallId) and (toolName, idempotencyKey) for dedup and replay protection |
| `ToolCatalogResolver` | Aggregates ToolCatalogProviderInterface, deduplicates, enforces schema stability |
| `ToolBatchCollector` | Bounded parallel dispatch — registers expected batch, gates by maxParallelism and Sequential/Interrupt mode, collects results, returns ordered batches with further dispatchable effects |
| `ToolBatchCollectOutcome` | Outcome VO: rejected/duplicate/acceptedPending(effects)/acceptedComplete(orderedResults, effects). Carries further dispatchable `ExecuteToolCall` effects |

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
