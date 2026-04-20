# Agent Core Architecture

The `agent-core` project implements a robust, event-sourced, and message-driven architectural pattern utilizing Domain-Driven Design (DDD). It separates pure domain logic from infrastructure adapters and side-effects.

## High-Level Workflow

The system is designed around a CQRS (Command Query Responsibility Segregation) pattern, driven by various message buses.

```text
+----------------+      [1. Dispatch]      +------------------+      [2. Handle]       +-------------------+
|   API / CLI    | ----------------------> |   AgentRunner    | ---------------------> |  RunOrchestrator  |
| (Controllers,  |                         |  (API Facade)    |   (Command Bus)        |  (CQRS Processor) |
|  Commands)     |                         +------------------+                        +-------------------+
+----------------+                                                                              |
                                                                                                | [3. Reduce & Produce Effects]
                                                                                                v
+-------------------+      [5. Execute Effects]   +------------------+      [4. Async Effects] +-------------------+
|  Workers /        | <-------------------------- |  Execution Bus   | <---------------------- |   RunReducer      |
|  Side-effects     |                             |  (Messenger)     |                         |  (Pure State)     |
+-------------------+                             +------------------+                         +-------------------+
```

## Core Modules

### 1. Application Layer (`src/Application`)
Coordinates runtime flow and orchestration.

- **`AgentRunner`**: Acts as the public API facade, translating high-level actions (start, continue, steer, cancel) into discrete messages dispatched onto the command bus.
- **`RunOrchestrator`**: The central CQRS processor. It processes commands (e.g., `StartRun`, `AdvanceRun`, `ApplyCommand`) and handles step results (`LlmStepResult`, `ToolCallResult`). It delegates to `RunReducer` to compute state transitions and handles concurrency/locking.
- **`RunReducer`**: A pure functional state reducer. It transforms the given `RunState` and an input message into a new `RunState` and a list of side-effects (`ExecuteLlmStep`, `ExecuteToolCall`) to be dispatched to the execution bus.
- **Workers (`src/Application/Handler`)**: Handlers that execute async side-effects. `ExecuteLlmStepWorker` interfaces with language models, and `ExecuteToolCallWorker` runs actual tools, yielding Results back to the Orchestrator.

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

## Data Flow: Commands, Reducers, and Events

1. **Intention**: `AgentRunner` dispatches `ApplyCommand` to the command bus.
2. **Orchestration**: `RunOrchestrator` acquires a lock, loads current `RunState`, and passes the command to `RunReducer`.
3. **Reduction**: `RunReducer` returns the next `RunState` and any required *effects* (e.g., `ExecuteLlmStep`).
4. **Commit & Project**: `RunOrchestrator` commits new events to Doctrine. The `OutboxProjector` catches committed events and delegates them to the JSONL log outbox and Mercure outbox.
5. **Effect Execution**: Effects are dispatched. `ExecuteLlmStepWorker` queries the LLM and dispatches `LlmStepResult` back to the orchestrator, starting the loop again until completion.
