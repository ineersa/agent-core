# Agent Core Architecture

The `agent-core` project implements a robust, event-sourced, and message-driven architectural pattern utilizing Domain-Driven Design (DDD). It separates pure domain logic from infrastructure adapters and side-effects.

## High-Level Workflow

The system is designed around a CQRS (Command Query Responsibility Segregation) pattern, driven by various message buses.

```text
+----------------+      [1. Dispatch]      +------------------+      [2. Entrypoint]    +-------------------+
|   API / CLI    | ----------------------> |   AgentRunner    | -----------------------> |  RunOrchestrator  |
| (Controllers,  |                         |  (API Facade)    |   (Command Bus)          |  (Bus Handler)    |
|  Commands)     |                         +------------------+                          +-------------------+
+----------------+                                                                                 |
                                                                                                   | [3. Shared Pipeline]
                                                                                                   v
                                                                                         +-------------------+
                                                                                         | RunMessageProcessor|
                                                                                         +-------------------+
                                                                                                   |
                                                                                                   | [4. Transition Build]
                                                                                                   v
                                                                                         +-------------------+
                                                                                         | Message Handlers   |
                                                                                         | (Start/Apply/...)  |
                                                                                         +-------------------+
                                                                                                   |
                                                                                                   | [5. Commit + Effects]
                                                                                                   v
+-------------------+      [7. Execute Effects]   +------------------+      [6. Dispatch] +-------------------+
|  Workers /        | <-------------------------- |  Execution Bus   | <------------------ |     RunCommit     |
|  Side-effects     |                             |  (Messenger)     |                    | (CAS + Outbox +   |
+-------------------+                             +------------------+                    |  Replay + Hooks)  |
                                                                                          +-------------------+
```

## Core Modules

### 1. Application Layer (`src/Application`)
Coordinates runtime flow and orchestration.

- **`AgentRunner`**: Acts as the public API facade, translating high-level actions (start, continue, steer, cancel) into discrete messages dispatched onto the command bus.
- **`RunOrchestrator`**: Thin message-bus entrypoint with root tracing spans. It delegates runtime processing to `RunMessageProcessor`.
- **`RunMessageProcessor`**: Shared runtime pipeline for lock, idempotency, state load, per-message handler routing, commit orchestration, and post-commit actions.
- **Dedicated message handlers (`src/Application/Orchestrator`)**: `StartRunHandler`, `ApplyCommandHandler`, `AdvanceRunHandler`, `LlmStepResultHandler`, and `ToolCallResultHandler` build `HandlerResult` transitions.
- **`RunCommit`**: Owns durable persistence lifecycle (CAS, event append, outbox projection, replay rebuild, effect dispatch, hooks, and commit observability).
- **Workers (`src/Application/Handler`)**: Handlers that execute async side-effects. `ExecuteLlmStepWorker` interfaces with language models, and `ExecuteToolCallWorker` runs actual tools, yielding results back to the orchestrator pipeline.

### 2. Domain Layer (`src/Domain`)
Contains framework-agnostic models, state representations, and message contracts.

- **`Run` / `RunState`**: Immutable value objects representing the lifecycle and current state of a running agent.
- **`Event` (Event Sourcing)**: `RunEvent` is the canonical persisted event envelope. The domain maps out an exact lifecycle order (`CoreLifecycleEventType`) like `agent_start` -> `turn_start` -> `message_start` -> `tool_execution` -> `turn_end`.
- **`Command`**: Value objects mapping intentional state changes (e.g., Core/Extension Commands).
- **`Message`**: Transports for buses (e.g., `StartRun`, `ExecuteLlmStep`).

### 3. Infrastructure Layer (`src/Infrastructure`)
Concrete adapters to external systems.

- **Doctrine**: Canonical persistence. Maintains tables for Runs, canonical Events, pending Commands, Tool Jobs, and Outbox logs (`Migrations/Version20260418000100.php`).
- **Messenger**: Symfony Messenger configuration for reliable routing across `agent.command.bus`, `agent.execution.bus`, and `agent.publisher.bus`.
- **Storage / Flysystem**: JSONL event logs are persisted to a durable file store (`JsonlOutboxProjectorWorker` -> `RunLogWriter`).
- **Mercure**: Real-time server-sent event (SSE) publisher (`MercureOutboxProjectorWorker`), pushing lifecycle events to `agent/runs/{runId}` topics.
- **SymfonyAI**: Bridges the core tool and execution mechanics with Symfony's LLM components.

## Data Flow: Commands, Handlers, and Events

1. **Intention**: `AgentRunner` dispatches messages (`StartRun`, `ApplyCommand`, `AdvanceRun`) to the command bus.
2. **Entrypoint**: `RunOrchestrator` receives the message and opens a root trace span.
3. **Processing Pipeline**: `RunMessageProcessor` enforces lock/idempotency boundaries, loads `RunState`, and routes to the matching message handler.
4. **Transition Build**: The handler produces `HandlerResult` (next state, durable events, commit effects, optional post-commit callbacks/effects).
5. **Commit & Project**: `RunCommit` persists state/events and projects events through `OutboxProjector` (JSONL + Mercure outboxes).
6. **Effect Execution**: Durable effects are dispatched to workers. `ExecuteLlmStepWorker` and `ExecuteToolCallWorker` emit result messages (`LlmStepResult`, `ToolCallResult`) that re-enter the same pipeline until terminal or paused state.
