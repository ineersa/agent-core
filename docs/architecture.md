# Agent Core Architecture

`agent-core` is an event-sourced, message-driven runtime for agent runs. It keeps domain state transitions explicit (commands/events/effects) and isolates side effects in workers/adapters.

## High-Level Workflow

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

### 1) Application (`src/Application`)
Coordinates orchestration and runtime flow.

- **`AgentRunner`**: public API facade (`start`, `continue`, `steer`, `followUp`, `cancel`, `answerHuman`) that emits command-bus messages.
- **`RunOrchestrator`**: command-bus entrypoint (`onStartRun`, `onApplyCommand`, `onAdvanceRun`, `onLlmStepResult`, `onToolCallResult`) with root tracing spans.
- **`RunMessageProcessor`**: shared lock/idempotency/load/handler/commit pipeline.
- **Message handlers** (`src/Application/Orchestrator`): `StartRunHandler`, `ApplyCommandHandler`, `AdvanceRunHandler`, `LlmStepResultHandler`, `ToolCallResultHandler`.
- **`RunCommit`**: CAS persistence + event append + outbox projection + replay rebuild + effect dispatch + hook dispatch + commit metrics.
- **Workers** (`src/Application/Handler`): `ExecuteLlmStepWorker`, `ExecuteToolCallWorker`, plus outbox projector workers.

### 2) Domain (`src/Domain`)
Framework-agnostic models and contracts.

- **Run model**: `RunState`, `RunStatus`, `RunHandle`, `RunId`.
- **Commands/messages**: `StartRun`, `ApplyCommand`, `AdvanceRun`, `ExecuteLlmStep`, `ExecuteToolCall`, `LlmStepResult`, `ToolCallResult`.
- **Events**: canonical `RunEvent` envelope with strict lifecycle ordering via `CoreLifecycleEventType::validateOrder()`.

### 3) Infrastructure (`src/Infrastructure`)
Concrete adapters used by the runtime.

- **Default runtime stores are in-memory**:
  - `InMemoryRunStore`
  - `InMemoryCommandStore`
  - `RunEventStore`
  - `InMemoryRunAccessStore`
  - `HotPromptStateStore` / `InMemoryPromptStateStore`
- **Run logs**: JSONL append/read via `RunLogWriter` and `RunLogReader` (Flysystem).
- **Mercure streaming**: `RunEventPublisher` publishes to `agent/runs/{runId}` (with `message_update` coalescing).
- **Symfony AI bridge**: platform/tool invocation adapters.
- **Doctrine namespace currently provides migration scaffolding** (`src/Infrastructure/Doctrine/Migrations`) rather than the active default storage backend.

### 4) API (`src/Api`)
Transport-facing HTTP layer.

- `RunApiController`: start run, send command, run summary, transcript page, replay events.
- `RunReadService`: read-model composition and replay-source fallback (`canonical_events` -> `jsonl_fallback`).
- `RunEventSerializer` / `RunStreamEvent`: domain-to-transport event mapping.

## Runtime Data Flow

1. **Intention**: API/CLI dispatches `StartRun`/`ApplyCommand`/`AdvanceRun`.
2. **Entrypoint**: `RunOrchestrator` receives message and starts a trace span.
3. **Shared pipeline**: `RunMessageProcessor` acquires lock, checks idempotency, loads state, resolves handler.
4. **Transition build**: handler returns `HandlerResult` (next state, events, effects, callbacks).
5. **Commit**: `RunCommit` persists state/events, projects outbox, rebuilds prompt snapshot, emits metrics/hooks.
6. **Effect dispatch**: effects go to execution/publisher buses.
7. **Loop continuation**: workers emit `LlmStepResult` / `ToolCallResult`, which re-enter step 2 until terminal or paused state.
