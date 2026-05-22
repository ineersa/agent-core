# Async headless runtime and Messenger worker plan

Date: 2026-05-21  
Status: revised — controller as event hub, publish bus for transient streaming deltas only

## Purpose

Make Hatfield's TUI/headless runtime responsive while AgentCore performs slow LLM and tool work.

FrameworkBundle is adopted (PR #37 merged). `messenger:consume` and `debug:messenger` are available. The next step is wiring async transports and a cross-process event delivery mechanism.

Core invariants:

- TUI talks only to `AgentSessionClient` / runtime protocol DTOs.
- `src/AgentCore/` remains the canonical run/state/event engine.
- `.hatfield/sessions/<id>/events.jsonl` remains the canonical event log (written by RunCommit, unchanged).
- `.hatfield/sessions/<id>/state.json` remains the run-state checkpoint/CAS file.
- `runtime-events.jsonl` is deleted. No transition period.

## Target process topology

```text
TUI process
  - owns terminal rendering/input
  - sends JSONL RuntimeCommand to controller
  - reads JSONL RuntimeEvent/command_ack from controller stdout
  - RuntimeEventPoller reads from controller stdout buffer (same as today)
        |
        v
Headless controller process (event loop)
  - stream_select-based event loop, never blocks
  - reads stdin for TUI commands → ACKs immediately → dispatches to Messenger
  - polls publish transport Receiver::get() → forwards RuntimeEvent JSONL to stdout
  - supervises Messenger consumers (start/restart/health)
  - does NOT run AgentCore LLM/tool work
  - does NOT store events — just forwards them
        |
        v
Messenger transports / consumers (Doctrine SQLite)
  run_control consumer
    - StartRun, ApplyCommand, AdvanceRun, LlmStepResult, ToolCallResult
    - owns canonical state transitions/commits
    - writes canonical events.jsonl (unchanged)

  llm consumer
    - ExecuteLlmStep
    - invokes Symfony AI / provider stream
    - publishes streaming deltas → publish transport
    - dispatches LlmStepResult back to run_control

  tool consumer
    - ExecuteToolCall
    - executes tool subprocesses/callables
    - publishes tool execution events → publish transport
    - dispatches ToolCallResult back to run_control
```

No separate publish consumer process. The controller IS the publish consumer — it polls the publish transport directly in its event loop and forwards to TUI.

## Event delivery: controller as event hub

### Why controller-relayed, not SQLite feed table

Previous plan had TUI polling SQLite directly. Problems:

- Hundreds of streaming deltas per few seconds × 3 parallel agents = massive write pressure on SQLite.
- No push model — TUI must poll, adding latency.
- Another storage layer to manage and clean up.

Better: the controller already has a JSONL pipe to TUI. It already sits between TUI and workers. Use it as the event hub — same role as Mercure, but over a pipe instead of SSE.

```text
workers → PublishRuntimeEvent → publish transport (SQLite queue)
                                          ↓
                    controller polls Receiver::get() (non-blocking)
                                          ↓
                    controller writes RuntimeEvent JSONL to stdout
                                          ↓
                    TUI RuntimeEventPoller reads from stdout buffer
```

Push model. No event feed table. No cleanup needed — Messenger transport handles ACK/delete automatically.

### Controller event loop

```php
// Simplified controller loop
while (true) {
    $read = [$stdin];
    $write = null;
    $except = null;

    // 1. Non-blocking check for TUI commands
    stream_select($read, $write, $except, 0, 10_000); // 10ms timeout
    if (in_array($stdin, $read)) {
        $command = readJsonlLine($stdin);
        if ($command) {
            writeJsonl($stdout, command_ack($command, 'accepted'));
            $commandBus->dispatch(mapCommand($command));
        }
    }

    // 2. Poll publish transport for runtime events
    $envelopes = $publishReceiver->get();
    foreach ($envelopes as $envelope) {
        $message = $envelope->getMessage(); // PublishRuntimeEvent
        writeJsonl($stdout, $message->event->toArray());
        $publishReceiver->ack($envelope); // removes from queue
    }

    // 3. Check child process health
    superviseConsumers();

    // 4. Brief sleep to avoid busy loop
    usleep(10_000); // 10ms
}
```

No ReactPHP needed. `stream_select` + Symfony Messenger `Receiver::get()` handles everything.

### Latency

- Command ACK: ~1-10ms (stdin read + stdout write + Messenger dispatch)
- Event forwarding: ~10-20ms (10ms poll interval + Receiver::get() + stdout write)
- Cancel propagation: ACK immediately, cancel state set in run_control consumer

## Publish bus design

### What the publish bus carries

**Only transient streaming deltas** (seq=0). Canonical events stay in `events.jsonl` — that path already works and doesn't change.

Publish sources:

1. **Stream subscribers** (`AssistantTextStreamSubscriber`, `AssistantThinkingStreamSubscriber`, `ToolCallStreamSubscriber`) — LLM/tool streaming deltas. These are the events that don't cross process boundaries today.

2. Canonical events from RunCommit do NOT go through the publish bus. They're already in `events.jsonl` and `InProcessAgentSessionClient::events()` reads them from `EventStore`.

### Interface in AgentCore

The publish contract lives in AgentCore. CodingAgent provides the Messenger implementation.

```php
// src/AgentCore/Contract/RuntimeEventPublisherInterface.php
interface RuntimeEventPublisherInterface
{
    /**
     * Publish a transient runtime event for live consumers.
     * Used for streaming deltas that don't appear in canonical events.jsonl.
     */
    public function publish(RuntimeEvent $event): void;
}
```

AgentCore calls this interface. CodingAgent wires the implementation:

```php
// src/CodingAgent/Runtime/Publish/MessengerRuntimeEventPublisher.php
final class MessengerRuntimeEventPublisher implements RuntimeEventPublisherInterface
{
    public function __construct(
        private MessageBusInterface $publisherBus,
    ) {}

    public function publish(RuntimeEvent $event): void
    {
        $this->publisherBus->dispatch(
            new PublishRuntimeEvent($event->runId, $event),
        );
    }
}
```

### Publisher message

```php
// src/AgentCore/Domain/Message/PublishRuntimeEvent.php
final readonly class PublishRuntimeEvent
{
    public function __construct(
        public string $runId,
        public RuntimeEvent $event,
    ) {}
}
```

Only carries transient events. No canonical_seq, no transient flag — everything on this bus is transient by definition.

### How stream subscribers use it

Current:
```php
// AssistantTextStreamSubscriber
$this->sink->emit($runtimeEvent); // InMemoryRuntimeEventSink or JsonlRuntimeEventSink
```

New (async mode):
```php
$this->publisher->publish($runtimeEvent); // → Messenger publish transport
```

The sink (`RuntimeEventSinkInterface`) continues to exist for in-process mode. In async mode, stream subscribers use the publisher instead.

### Cleanup

No event feed table means no cleanup. The controller ACKs envelopes from the publish transport as it forwards them. Messenger Doctrine transport handles row deletion automatically.

Old messages in the transport queue from crashed sessions are cleaned when the controller starts or via `messenger:setup-transports` which can reset queues.

## Transport: Doctrine SQLite

All Messenger transports use Doctrine transport backed by a single SQLite database.

```bash
composer require symfony/doctrine-messenger doctrine/doctrine-bundle
```

```yaml
# config/packages/doctrine.yaml
doctrine:
  dbal:
    default_connection: default
    connections:
      default:
        url: 'sqlite:///%kernel.project_dir%/.hatfield/messenger.sqlite'

# config/packages/framework.yaml (messenger section)
framework:
  messenger:
    default_bus: agent.command.bus
    buses:
      agent.command.bus:
        default_middleware: { enabled: true, allow_no_handlers: false, allow_no_senders: true }
      agent.execution.bus:
        default_middleware: { enabled: true, allow_no_handlers: false, allow_no_senders: true }
      agent.publisher.bus:
        default_middleware: { enabled: true, allow_no_handlers: true, allow_no_senders: true }
    transports:
      run_control: 'doctrine://default?queue_name=run_control'
      llm:         'doctrine://default?queue_name=llm'
      tool:        'doctrine://default?queue_name=tool'
      publish:     'doctrine://default?queue_name=publish'
    routing:
      StartRun:             { send: run_control }
      ApplyCommand:         { send: run_control }
      AdvanceRun:           { send: run_control }
      LlmStepResult:        { send: run_control }
      ToolCallResult:       { send: run_control }
      ExecuteLlmStep:       { send: llm }
      ExecuteToolCall:      { send: tool }
      PublishRuntimeEvent:  { send: publish }
```

Database file: `.hatfield/messenger.sqlite` — only Messenger transport tables. No event feed table.

## Message routing model

### Three buses

```text
agent.command.bus (sync within run_control consumer process)
  - StartRun, ApplyCommand, AdvanceRun, LlmStepResult, ToolCallResult
  - self-advance callbacks (postCommit) dispatch back here
  - stays synchronous — the run_control consumer owns this

agent.execution.bus (async via Doctrine transport)
  - ExecuteLlmStep → llm transport
  - ExecuteToolCall → tool transport

agent.publisher.bus (async via Doctrine transport)
  - PublishRuntimeEvent → publish transport
  - controller polls and forwards to TUI
```

### Routing summary

| Message | Bus | Transport | Consumer |
|---|---|---|---|
| StartRun | command | run_control | RunOrchestrator::onStartRun |
| ApplyCommand | command | run_control | RunOrchestrator::onApplyCommand |
| AdvanceRun | command | run_control | RunOrchestrator::onAdvanceRun |
| LlmStepResult | command | run_control | RunOrchestrator::onLlmStepResult |
| ToolCallResult | command | run_control | RunOrchestrator::onToolCallResult |
| ExecuteLlmStep | execution | llm | ExecuteLlmStepWorker |
| ExecuteToolCall | execution | tool | ExecuteToolCallWorker |
| PublishRuntimeEvent | publisher | publish | Controller event loop (Receiver::get) |

### Result routing

Workers dispatch results back to command bus through Doctrine transport:

- `ExecuteLlmStepWorker` → `LlmStepResult` → run_control transport
- `ExecuteToolCallWorker` → `ToolCallResult` → run_control transport

### Critical: command bus stays sync per-process

The command bus self-advance pattern (postCommit callbacks dispatch AdvanceRun synchronously) only works within a single run-control consumer process. Command bus messages must NOT route to Doctrine transport.

### Critical: publish transport is fire-and-forget

The controller ACKs publish messages as it forwards them. If the controller crashes, un-ACKed messages remain in the transport and are picked up on restart. Transient deltas may be lost during a crash — this is acceptable since they're streaming snapshots, not canonical state.

## Controller responsibilities

The controller is a thin event loop process.

It must:

- run a `stream_select`-based event loop that never blocks;
- read TUI JSONL commands from stdin (non-blocking);
- ACK commands immediately (write `command_ack` to stdout);
- dispatch valid commands to Messenger;
- poll publish transport `Receiver::get()` and forward events to stdout;
- launch and supervise `messenger:consume` child processes (run_control, llm, tool);
- capture child stderr/stdout into logs;
- escalate hard-cancel by stopping a worker process after graceful cancellation timeout.

It must not:

- perform LLM calls;
- execute tools;
- process AgentCore state transitions;
- buffer or store events — forward and forget;
- become a replacement for Messenger consumers.

### Consumer supervision

```text
controller starts:
  messenger:consume run_control  (child process 1)
  messenger:consume llm          (child process 2)
  messenger:consume tool         (child process 3)
```

Controller monitors child processes. On crash:

- log the failure;
- restart the consumer;
- if restart count exceeds threshold, report error to TUI.

## What changes in existing code

### RuntimeEventPoller — no change needed

The poller already reads from `AgentSessionClient::events()` which in process mode reads from stdout. The controller forwards the same JSONL events. The poller doesn't need to know whether events came from in-process memory or from a controller pipe.

### RuntimeEventSinkInterface — unchanged for in-process

`InMemoryRuntimeEventSink` and `JsonlRuntimeEventSink` continue to work for in-process mode. No changes.

### Stream subscribers — add publisher in async mode

Current:
```php
$this->sink->emit($runtimeEvent);
```

New (async mode):
```php
$this->publisher->publish($runtimeEvent);
```

This is a coding-agent-level wiring change. AgentCore defines `RuntimeEventPublisherInterface`; coding agent provides the Messenger implementation.

### RunCommit — unchanged

Canonical events stay in `events.jsonl`. RunCommit doesn't touch the publish bus.

### runtime-events.jsonl — deleted

Remove all references:
- `HatfieldSessionStore::initializeSession()` — remove `file_put_contents($sessionPath.'/runtime-events.jsonl', '')`.
- `RuntimeEventPoller::poll()` — remove `$this->sessionStore->appendRuntimeEvent()`.
- `HatfieldSessionStoreTest` — remove assertion on `runtime-events.jsonl`.
- `TuiAgentSmokeTest` — remove from artifact list.
- `docs/session-storage.md` — remove references.

## Runtime protocol changes

Add command acknowledgements:

```json
{
  "v": 1,
  "type": "command_ack",
  "runId": "...",
  "seq": 0,
  "payload": {
    "commandId": "cmd_...",
    "commandType": "cancel",
    "status": "accepted"
  }
}
```

Add `RuntimeEventTypeEnum::CommandAck`.

## Storage summary

### What stays

| File | Role | Writer |
|---|---|---|
| `events.jsonl` | Canonical event log | RunCommit via SessionRunEventStore |
| `state.json` | Run-state CAS checkpoint | RunOrchestrator via SessionRunStore |
| `metadata.yaml` | Session identity | SessionInitializer |
| `transcript.jsonl` | Transcript projection | TranscriptProjector |
| `messenger.sqlite` | Messenger transport queues | Doctrine transport |

### What is deleted

| File | Reason |
|---|---|
| `runtime-events.jsonl` | Replaced by controller-relayed publish events. No transition period. |

### Workers reading state

Workers read shared state from session files (unchanged):

- `LlmPlatformAdapter` resolves context from `RunStore` using `runId`.
- Tool execution uses `RunStore` for cancellation via `RunCancellationToken`.
- Run-control handlers load/commit `RunState` through session stores.

## Required hardening before multi-process is safe

| Area | Current risk | Target |
|---|---|---|
| Idempotency | `MessageIdempotencyService` is in-memory | persistent per-session or transport-backed idempotency store |
| CAS conflicts | failed `compareAndSwap()` can drop progress | retry/backoff or explicit retryable worker failure |
| Runtime stream | `InMemoryRuntimeEventSink` is per-process | Publish bus + controller relay |
| Serialization | some result payloads include DTO/value objects | serializer round-trip tests for every transported message |
| Supervision | `AgentProcessSupervisor` is scaffold-level | start/restart/heartbeat/log capture for consumers |
| Cancellation | graceful cancellation depends on worker checks | ACK immediately, set cancellation state, escalate hard kill later |

## Implementation phases

### Phase 0 — FrameworkBundle/Messenger foundation ✅ DONE

Completed in PR #37.

### Phase 1 — Doctrine SQLite transport + publish bus infrastructure

Goal: establish the transport layer and publish bus.

Tasks:

- install `symfony/doctrine-messenger` and `doctrine/doctrine-bundle`;
- configure Doctrine DBAL with SQLite at `.hatfield/messenger.sqlite`;
- add `agent.publisher.bus` to framework.yaml;
- define four Doctrine transports (run_control, llm, tool, publish);
- create `RuntimeEventPublisherInterface` in AgentCore `Contract/`;
- create `PublishRuntimeEvent` message in AgentCore `Domain/Message/`;
- create `MessengerRuntimeEventPublisher` in CodingAgent implementing the interface;
- wire publisher bus transport and alias;
- verify with `debug:messenger`.

Acceptance criteria:

- `bin/console debug:messenger` shows all three buses, four transports;
- `MessengerRuntimeEventPublisher` dispatches to publish transport;
- `Receiver::get()` on publish transport returns dispatched messages;
- all existing tests still pass (sync mode unchanged).

### Phase 2 — Wire publish sources + delete runtime-events.jsonl

Goal: make workers publish transient events, remove runtime-events.jsonl.

Tasks:

- wire stream subscribers to use `RuntimeEventPublisherInterface` in async mode;
- delete all `runtime-events.jsonl` references (creation, writing, assertions, docs);
- update `HatfieldSessionStore::initializeSession()` to not create the file;
- update `RuntimeEventPoller::poll()` to not write to the file;
- update tests (`HatfieldSessionStoreTest`, `TuiAgentSmokeTest`);
- update `docs/session-storage.md`.

Acceptance criteria:

- streaming deltas are dispatched to publish transport;
- no `runtime-events.jsonl` file created anywhere;
- all tests pass;
- in-process mode still works (uses sink, not publisher).

### Phase 3 — Controller event loop + command ACK

Goal: build the controller process with event loop, command handling, and publish forwarding.

Tasks:

- implement controller event loop using `stream_select` + `Receiver::get()`;
- add `command_ack` runtime event type;
- controller reads stdin JSONL, ACKs, dispatches to Messenger;
- controller polls publish transport, forwards to stdout JSONL;
- controller launches/supervises `messenger:consume run_control`, `llm`, `tool`;
- controller handles hard-cancel (stop worker process).

Acceptance criteria:

- controller accepts commands and ACKs within ~10ms;
- controller forwards publish events to stdout within ~20ms;
- controller supervises consumers (restart on crash);
- TUI works through controller (prompt → response visible).

### Phase 4 — Async execution transports

Goal: move slow LLM/tool work out of the run-control path.

Tasks:

- route `ExecuteLlmStep` to `llm` transport;
- route `ExecuteToolCall` to `tool` transport;
- route `LlmStepResult` / `ToolCallResult` back to `run_control`;
- keep run-control single-consumer at first.

Acceptance criteria:

- controller command handling returns quickly after scheduling execution;
- LLM/tool work runs in separate consumer process;
- canonical `events.jsonl` and `state.json` remain correct;
- streaming deltas flow through publish bus → controller → TUI;
- steer/cancel commands arrive while LLM work is in progress.

### Phase 5 — Async run-control consumer

Goal: make orchestration itself a proper consumer path.

Tasks:

- route `StartRun`, `ApplyCommand`, `AdvanceRun`, `LlmStepResult`, `ToolCallResult` to `run_control`;
- controller dispatches and returns to event loop;
- ensure self-advance callbacks enqueue correctly;
- validate terminal/stale-result/idempotent behavior.

Acceptance criteria:

- controller never blocks on run-control processing;
- run-control consumer can be restarted without corrupting state;
- one run progresses from start to LLM to result to completion through transports.

### Phase 6 — Persistent idempotency, CAS retry, and supervision

Goal: make the multi-process topology robust.

Tasks:

- persist idempotency keys;
- make CAS conflicts retryable;
- harden consumer supervision;
- add heartbeat/restart policy;
- add hard-cancel escalation.

Acceptance criteria:

- duplicate command/result messages do not duplicate canonical events;
- worker restart does not lose queued work;
- cancel ACKs quickly and escalates if graceful fails;
- multiple sessions/runs do not corrupt each other.

## Testing and validation

Per AGENTS.md, runtime/TUI changes require product-level Castor validation.

```bash
castor test
castor deptrac
castor phpstan [changed paths]
castor cs-check
castor run:agent-test
```

Suggested new tests:

- `debug:messenger` shows all three buses with correct transports/handlers;
- serializer round-trip for `PublishRuntimeEvent` and all transported messages;
- `MessengerRuntimeEventPublisher` dispatches to correct transport;
- controller event loop forwards publish events to stdout;
- command ACK emitted before fake slow LLM worker completes;
- cancel during slow LLM ACKs immediately and updates state;
- duplicate publish is handled correctly (transport dedup or idempotent projection);
- `runtime-events.jsonl` is never created.

## Risks and mitigations

| Risk | Impact | Mitigation |
|---|---|---|
| SQLite write contention under load | Medium | Only Messenger transport tables; WAL mode for concurrent read/write |
| Message serialization fails for nested RuntimeEvent DTOs | High | Explicit transport serializer tests before enabling workers |
| In-memory idempotency duplicates events | High | Persist idempotency before multi-worker scaling |
| Controller crash loses in-flight transients | Low | Acceptable — transients are streaming snapshots, not state |
| Command bus becomes async by mistake | High | Explicit routing config; command bus never routes to Doctrine transport |
| Controller event loop stalls | Medium | Keep controller thin; measure tick latency; add watchdog timer |
| Removing runtime-events.jsonl breaks in-process path | Medium | In-process path never reads the file (uses InMemoryRuntimeEventSink) |

## Recommended task breakdown

1. **ASYNC-00** ~~FrameworkBundle CLI infrastructure~~ ✅ DONE (PR #37)

2. **ASYNC-01 Doctrine SQLite transport + publish bus**
   - Install doctrine-messenger + doctrine-bundle.
   - Configure SQLite DBAL connection.
   - Add `agent.publisher.bus` and four Doctrine transports.
   - Create `RuntimeEventPublisherInterface` in AgentCore, `PublishRuntimeEvent` message, `MessengerRuntimeEventPublisher` in CodingAgent.
   - Verify with `debug:messenger`.

3. **ASYNC-02 Wire publish sources + delete runtime-events.jsonl**
   - Wire stream subscribers to publisher in async mode.
   - Delete all runtime-events.jsonl references.
   - All tests pass, no file created.

4. **ASYNC-03 Controller event loop + command ACK**
   - Build controller with stream_select loop.
   - Command ACK/reject. Consumer supervision.
   - Publish forwarding to stdout.

5. **ASYNC-04 Async execution transports**
   - Route LLM/tool to async transports.
   - Verify streaming through publish bus.

6. **ASYNC-05 Async run-control consumer**
   - Route command messages to run_control transport.
   - Validate self-advance across transport.

7. **ASYNC-06 Persistent idempotency, CAS retry, supervision**
   - Harden for production multi-process use.

## Future: Mercure for web

The `agent.publisher.bus` is the natural place to add Mercure back. When web UIs are needed:

- add a second handler on the publish transport (`MercurePublishWorker`);
- or use Messenger worker routing to send publish messages to both controller and Mercure consumer.

The `OutboxSink` concept can return as `Pipe` (TUI) and `Mercure` (web).

## History

- 2026-05-21: initial plan (no FrameworkBundle, custom wiring)
- 2026-05-21: revised for FrameworkBundle adoption
- 2026-05-21: revised with publish bus + SQLite event feed table
- 2026-05-21: revised with controller as event hub — no feed table, controller polls publish transport and pushes to TUI, runtime-events.jsonl deleted, publish bus carries only transient streaming deltas, canonical events stay in events.jsonl
