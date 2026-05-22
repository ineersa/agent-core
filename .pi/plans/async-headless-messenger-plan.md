# Async headless runtime and Messenger worker plan

Date: 2026-05-21  
Status: revised — publish bus + SQLite event feed model, RuntimeEventPoller polls DB directly

## Purpose

Make Hatfield's TUI/headless runtime responsive while AgentCore performs slow LLM and tool work.

FrameworkBundle is adopted (PR #37 merged). `messenger:consume` and `debug:messenger` are available. The next step is wiring async transports and a cross-process event delivery mechanism.

Core invariants:

- TUI talks only to `AgentSessionClient` / runtime protocol DTOs.
- `src/AgentCore/` remains the canonical run/state/event engine.
- `.hatfield/sessions/<id>/events.jsonl` remains the canonical event log.
- `.hatfield/sessions/<id>/state.json` remains the run-state checkpoint/CAS file.
- `runtime-events.jsonl` is deprecated as a live transport; may remain as optional debug artifact temporarily but will be removed.

## Target process topology

```text
TUI process
  - owns terminal rendering/input
  - sends JSONL RuntimeCommand to controller
  - RuntimeEventPoller polls SQLite event feed directly
  - no file tailing, no JSONL event parsing from controller
        |
        v
Headless controller process
  - nonblocking stdin/stdout JSONL loop
  - validates and ACKs commands quickly
  - dispatches StartRun / ApplyCommand into Messenger
  - launches/supervises Messenger consumers
  - does NOT relay events to TUI (TUI reads feed directly)
  - does NOT run AgentCore LLM/tool work inline
        |
        v
Messenger transports / consumers (Doctrine SQLite)
  run_control consumer
    - StartRun, ApplyCommand, AdvanceRun, LlmStepResult, ToolCallResult
    - owns canonical state transitions/commits
    - publishes RuntimeEvent → publish bus

  llm consumer
    - ExecuteLlmStep
    - invokes Symfony AI / provider stream
    - publishes streaming deltas → publish bus
    - dispatches LlmStepResult back to run_control

  tool consumer
    - ExecuteToolCall
    - executes tool subprocesses/callables
    - publishes tool execution events → publish bus
    - dispatches ToolCallResult back to run_control

  publish consumer
    - PublishRuntimeEvent messages
    - writes to runtime_event_feed SQLite table
    - single writer to avoid contention
```

## Transport: Doctrine SQLite

All Messenger transports use Doctrine transport backed by a single SQLite database.

Required packages (already have `doctrine/dbal`):

```bash
composer require symfony/doctrine-messenger doctrine/doctrine-bundle
```

Database file: `.hatfield/messenger.sqlite`

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
    transports:
      run_control: 'doctrine://default?queue_name=run_control'
      llm:         'doctrine://default?queue_name=llm'
      tool:        'doctrine://default?queue_name=tool'
      publish:     'doctrine://default?queue_name=publish'
    routing:
      # command bus messages → run_control
      StartRun:          { send: run_control }
      ApplyCommand:      { send: run_control }
      AdvanceRun:        { send: run_control }
      LlmStepResult:     { send: run_control }
      ToolCallResult:    { send: run_control }
      # execution bus messages
      ExecuteLlmStep:    { send: llm }
      ExecuteToolCall:   { send: tool }
      # publish messages
      PublishRuntimeEvent: { send: publish }
```

Same database also holds the `runtime_event_feed` table for TUI polling.

## Publish bus and event feed

### The old publisher bus

Agent-core previously had `agent.publisher.bus` with `OutboxProjector`, `OutboxStoreInterface`, `OutboxSink`, `InMemoryOutboxStore`, `JsonlOutboxProjectorWorker`, and `MercureOutboxProjectorWorker`. All were removed during RTVS-07 cleanup because they had become dead code (the outbox projector was removed from RunCommit, StepDispatcher.publish() had zero callers).

The old outbox had design issues we want to avoid:

- `InMemoryOutboxStore` was not persistent (useless for multi-process).
- It operated on domain `RunEvent` objects, not protocol `RuntimeEvent` DTOs.
- The Mercure publisher was always a noop ($hub was null).
- The JSONL outbox wrote to cold Flysystem storage (also removed).

### New design: publish bus + SQLite event feed

We rebuild the concept correctly:

**Publisher message:**

```php
// src/AgentCore/Domain/Message/PublishRuntimeEvent.php
final readonly class PublishRuntimeEvent
{
    public function __construct(
        public string $runId,
        public RuntimeEvent $event,
        public bool $transient,      // seq=0 streaming deltas
        public ?int $canonicalSeq,   // null for transients, seq>0 for canonical
    ) {}
}
```

**Publisher sources (where messages are dispatched from):**

1. **Stream subscribers** (`AssistantTextStreamSubscriber`, `AssistantThinkingStreamSubscriber`, `ToolCallStreamSubscriber`) — emit streaming deltas (seq=0, transient=true) via `RuntimeEventSinkInterface`.

2. **RunCommit::commit()** — after canonical events are persisted, publishes stable runtime events (seq>0, transient=false).

Both call sites dispatch `PublishRuntimeEvent` to `agent.publisher.bus`.

**RuntimeEventSinkInterface replacement:**

The current `InMemoryRuntimeEventSink` (in-process only) gets a new companion:
`MessengerPublishRuntimeEventSink` that dispatches to `agent.publisher.bus`.

In multi-process mode, the sink alias switches from `InMemoryRuntimeEventSink` to `MessengerPublishRuntimeEventSink`. Workers use the Messenger-based sink; the publish consumer writes to the shared feed.

**Publish consumer (the single writer):**

```php
// Handler for PublishRuntimeEvent on agent.publisher.bus
#[AsMessageHandler(bus: 'agent.publisher.bus')]
final class PublishRuntimeEventWorker
{
    public function __construct(
        private RuntimeEventFeedStore $feedStore,
    ) {}

    public function __invoke(PublishRuntimeEvent $message): void
    {
        $this->feedStore->append(
            runId: $message->runId,
            event: $message->event,
            transient: $message->transient,
            canonicalSeq: $message->canonicalSeq,
        );
    }
}
```

**Feed store table:**

```sql
CREATE TABLE runtime_event_feed (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    run_id TEXT NOT NULL,
    canonical_seq INTEGER DEFAULT NULL,
    transient BOOLEAN NOT NULL DEFAULT 0,
    type TEXT NOT NULL,
    payload_json TEXT NOT NULL,
    created_at TEXT NOT NULL DEFAULT (datetime('now'))
);
CREATE INDEX idx_feed_run_id_id ON runtime_event_feed(run_id, id);
```

### How TUI reads events

`RuntimeEventPoller` polls the SQLite feed table directly — same model as Mercure (subscriber reads from hub). No JSONL file, no controller relay.

```php
// Simplified RuntimeEventPoller::poll() in async mode
public function poll(TuiSessionState $state): ?array
{
    $rows = $this->feedStore->fetchAfter(
        runId: $state->handle->runId,
        afterId: $state->lastFeedId,  // replaces lastSeq for feed cursor
    );

    if (empty($rows)) {
        return null;
    }

    foreach ($rows as $row) {
        $event = RuntimeEvent::fromArray(json_decode($row['payload_json'], true));
        // ... existing projection logic (extractFooterUsage, updateActivity, projector->accept)
        $state->lastFeedId = $row['id'];
    }

    return self::synchronizeProjectedBlocks($state, $this->projector->blocks());
}
```

The TUI process opens its own SQLite connection (read-only is fine). SQLite WAL mode allows concurrent readers while the publish consumer writes.

### Event feed cleanup

Lifecycle-bounded, no per-tick deletion:

- **During run:** poller reads `WHERE run_id = ? AND id > ?`. No deletes. Table stays small per run.
- **On run terminal (Completed/Failed/Cancelled):** `DELETE FROM runtime_event_feed WHERE run_id = ?`. Run is done, TUI has projected everything.
- **On session start (new run or resume):** `DELETE FROM runtime_event_feed WHERE run_id = ?`. Clear stale events from previous crash.
- **Periodic housekeeping (optional):** `DELETE FROM runtime_event_feed WHERE created_at < datetime('now', '-7 days')`. Catches abandoned sessions.

No mark-and-sweep, no transaction-per-tick overhead. The `id > lastId` cursor pattern is inherently deduplicating and O(log n).

### Why not controller-relayed JSONL?

- Controller relaying creates an unnecessary bottleneck and single point of failure.
- SQLite WAL mode handles concurrent reads efficiently.
- The feed table doubles as a debug artifact (query by run_id, type, time range).
- Same model as Mercure: publisher writes to hub, subscribers read from hub.

### What about Mercure for web?

The publish bus is the right place to add Mercure back later. A second publish consumer (`MercurePublishWorker`) can consume from the same transport and push to a Mercure hub for web clients. The `OutboxSink` enum can return as `Feed`, `Mercure`, or similar.

For now, only the SQLite feed consumer exists.

## Message routing model

### Three buses

```text
agent.command.bus (sync within process)
  - StartRun, ApplyCommand, AdvanceRun, LlmStepResult, ToolCallResult
  - run-control consumer owns this bus's handler dispatch
  - self-advance callbacks (postCommit) dispatch back here

agent.execution.bus (async via Doctrine transport)
  - ExecuteLlmStep → llm transport
  - ExecuteToolCall → tool transport

agent.publisher.bus (async via Doctrine transport)
  - PublishRuntimeEvent → publish transport
  - publish consumer writes to runtime_event_feed
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
| PublishRuntimeEvent | publisher | publish | PublishRuntimeEventWorker |

### Result routing

Workers dispatch results back to command bus:

- `ExecuteLlmStepWorker` → `LlmStepResult` → run_control transport
- `ExecuteToolCallWorker` → `ToolCallResult` → run_control transport

In async mode these go through Doctrine transport, not in-process recursion.

### Critical: command bus stays sync per-process

The command bus self-advance pattern (postCommit callbacks dispatch AdvanceRun synchronously) only works within a single run-control consumer process. The command bus must NOT become async across processes — only execution and publish buses cross process boundaries.

## Controller responsibilities

The controller process should be boring.

It may:

- launch and restart `messenger:consume run_control`, `llm`, `tool`, and `publish` child processes;
- read TUI JSONL commands without blocking on agent execution;
- emit `command_ack` / rejected ACK quickly;
- dispatch valid commands to Messenger;
- capture child stderr/stdout into logs for debugging;
- escalate hard-cancel by stopping a worker process only after graceful cancellation times out.

It should not:

- perform LLM calls;
- execute tools;
- manually process AgentCore state transitions;
- relay runtime events to TUI (TUI polls SQLite directly);
- become a replacement for Messenger consumers.

## Storage and state sharing

### Canonical files

- `.hatfield/sessions/<id>/events.jsonl` — canonical append-only event stream, source for replay.
- `.hatfield/sessions/<id>/state.json` — materialized RunState checkpoint, CAS-protected.
- `.hatfield/sessions/<id>/metadata.yaml` — session identity/metadata.

### Messenger/event feed database

- `.hatfield/messenger.sqlite` — shared database for:
  - Messenger Doctrine transport queues (4 tables, one per transport)
  - `runtime_event_feed` table (TUI polling source)

### Projection files

- `.hatfield/sessions/<id>/transcript.jsonl` — user-facing transcript projection, rebuildable from canonical events.
- `runtime-events.jsonl` — deprecated. May remain as debug artifact temporarily, will be removed once feed-based polling is proven.

### Workers reading state

Workers read shared state from session files:

- `LlmPlatformAdapter` resolves context from `RunStore` using `runId`.
- Tool execution uses `RunStore` for cancellation via `RunCancellationToken`.
- Run-control handlers load/commit `RunState` through session stores.

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

Recommended additions:

- `RuntimeEventTypeEnum::CommandAck`
- `ping` / `pong` command-event pair for controller health

## What changes in existing code

### RuntimeEventPoller

Current flow:
```
TickPollListener → RuntimeEventPoller::poll($state, $client)
  → $client->events($runId)
    → [InProcess] InMemoryRuntimeEventSink::drain() + EventStore
  → appendRuntimeEvent() → runtime-events.jsonl
  → projector->accept()
```

New flow (async mode):
```
TickPollListener → RuntimeEventPoller::poll($state)
  → $feedStore->fetchAfter($runId, $state->lastFeedId)
  → projector->accept()
  → state->lastFeedId = last row id
```

The poller no longer calls `AgentSessionClient::events()` in async mode. It reads from the SQLite feed table directly. In-process mode continues using the existing path for backward compatibility.

### RuntimeEventSinkInterface

Current: `emit(RuntimeEvent)` → `InMemoryRuntimeEventSink` (in-process) or `JsonlRuntimeEventSink` (headless).

New: add `MessengerPublishRuntimeEventSink` that dispatches `PublishRuntimeEvent` to `agent.publisher.bus`. Wired as the default sink alias in async mode.

### Stream subscribers

Current: `AssistantTextStreamSubscriber`, `AssistantThinkingStreamSubscriber`, `ToolCallStreamSubscriber` call `$this->sink->emit(RuntimeEvent)`.

No change needed in subscribers themselves. The sink implementation switches from in-memory to Messenger-based.

### RunCommit

Current: no publish call (old OutboxProjector was removed).

New: after canonical events are persisted and mapped to RuntimeEvent, dispatch `PublishRuntimeEvent` for each stable event to `agent.publisher.bus`.

## Required hardening before multi-process is safe

| Area | Current risk | Target |
|---|---|---|
| Idempotency | `MessageIdempotencyService` is in-memory | persistent per-session or transport-backed idempotency store |
| CAS conflicts | failed `compareAndSwap()` can drop progress | retry/backoff or explicit retryable worker failure |
| Runtime stream | `InMemoryRuntimeEventSink` is per-process | Messenger publish bus + SQLite feed |
| Serialization | some result payloads include DTO/value objects | serializer round-trip tests for every transported message |
| Supervision | `AgentProcessSupervisor` is scaffold-level | start/restart/heartbeat/log capture for consumers |
| Cancellation | graceful cancellation depends on worker checks | ACK immediately, set cancellation state, escalate hard kill later |

## Implementation phases

### Phase 0 — FrameworkBundle/Messenger foundation ✅ DONE

Completed in PR #37. FrameworkBundle adopted, Messenger commands available, custom compiler pass removed.

### Phase 1 — Doctrine SQLite transport + publish bus

Goal: establish the transport layer and publish bus infrastructure.

Tasks:

- install `symfony/doctrine-messenger` and `doctrine/doctrine-bundle`;
- configure Doctrine DBAL with SQLite at `.hatfield/messenger.sqlite`;
- add `agent.publisher.bus` to framework.yaml;
- define four Doctrine transports (run_control, llm, tool, publish);
- create `PublishRuntimeEvent` message class;
- create `PublishRuntimeEventWorker` handler;
- create `RuntimeEventFeedStore` with `runtime_event_feed` table;
- create `MessengerPublishRuntimeEventSink` implementing `RuntimeEventSinkInterface`;
- wire publish bus transport and handler;
- verify with `debug:messenger`.

Acceptance criteria:

- `bin/console debug:messenger` shows all four buses, transports, and handlers;
- `bin/console messenger:consume publish` processes a test message;
- `RuntimeEventFeedStore::append()` and `::fetchAfter()` work correctly;
- all existing tests still pass (sync mode unchanged).

### Phase 2 — Wire publish sources + RuntimeEventPoller feed polling

Goal: make workers publish events and TUI read from feed.

Tasks:

- wire stream subscribers → `MessengerPublishRuntimeEventSink` in async mode;
- wire `RunCommit::commit()` to publish stable runtime events;
- add feed-based polling mode to `RuntimeEventPoller`;
- TuiSessionState gets `lastFeedId` field for feed cursor;
- deprecate `runtime-events.jsonl` writes in poller (keep behind flag for transition);
- add feed cleanup on terminal state / session start.

Acceptance criteria:

- LLM streaming deltas appear in `runtime_event_feed` table during a run;
- RuntimeEventPoller reads deltas from feed and renders in TUI;
- canonical events appear in feed after RunCommit;
- feed cleanup runs on run completion;
- in-process mode still works without feed table (backward compat).

### Phase 3 — command ACK and controller skeleton

Goal: separate command receipt from command execution at the protocol level.

Tasks:

- add `command_ack` runtime event type;
- make headless controller ACK valid JSONL commands before dispatching Messenger messages;
- reject invalid commands with rejected ACK;
- controller launches/supervises four Messenger consumers.

Acceptance criteria:

- parent can correlate every command ID to an ACK;
- ACK is emitted before long-running work completes;
- existing in-process TUI behavior remains unchanged.

### Phase 4 — async execution transports

Goal: move slow LLM/tool work out of the run-control path.

Tasks:

- route `ExecuteLlmStep` to `llm` transport;
- route `ExecuteToolCall` to `tool` transport;
- route `LlmStepResult` / `ToolCallResult` back to `run_control`;
- controller launches `messenger:consume llm` and `messenger:consume tool`;
- keep run-control single-consumer at first.

Acceptance criteria:

- controller command handling returns quickly after scheduling execution;
- LLM/tool work runs in separate consumer process;
- canonical `events.jsonl` and `state.json` remain correct;
- streaming deltas flow through publish bus to feed to TUI;
- steer/cancel commands arrive while LLM work is in progress.

### Phase 5 — async run-control consumer

Goal: make orchestration itself a proper consumer path.

Tasks:

- route `StartRun`, `ApplyCommand`, `AdvanceRun`, `LlmStepResult`, `ToolCallResult` to `run_control`;
- controller dispatches and returns to JSONL loop;
- ensure self-advance callbacks enqueue correctly;
- validate terminal/stale-result/idempotent behavior.

Acceptance criteria:

- controller never blocks on run-control processing;
- run-control consumer can be restarted without corrupting state;
- one run progresses from start to LLM to result to completion through transports.

### Phase 6 — persistent idempotency, CAS retry, and supervision

Goal: make the multi-process topology robust.

Tasks:

- persist idempotency keys;
- make CAS conflicts retryable;
- supervise all consumers;
- capture stderr/stdout logs;
- add heartbeat/restart policy;
- add hard-cancel escalation.

Acceptance criteria:

- duplicate command/result messages do not duplicate canonical events;
- worker restart does not lose queued work;
- cancel ACKs quickly and escalates if graceful fails;
- multiple sessions/runs do not corrupt each other.

## Testing and validation

Per AGENTS.md, runtime/TUI changes require product-level Castor validation.

Required validation for each implementation slice touching runtime/TUI:

```bash
castor test
castor deptrac
castor phpstan [changed paths]
castor cs-check
castor run:agent-test
```

Product validation must exercise:

1. start agent in tmux;
2. type prompt, submit;
3. wait for visible assistant response;
4. send steer/cancel during in-flight work for async slices;
5. capture TUI snapshot and session artifacts on failure.

Suggested new tests:

- `debug:messenger` shows all four buses with correct transports/handlers;
- `RuntimeEventFeedStore` round-trip (append → fetchAfter);
- serializer round-trip for `PublishRuntimeEvent` and all transported messages;
- command ACK emitted before fake slow LLM worker completes;
- feed cleanup on terminal state;
- duplicate publish is idempotent in feed (dedupe by run_id + canonical_seq);
- cancel during slow LLM updates state in feed.

## Risks and mitigations

| Risk | Impact | Mitigation |
|---|---|---|
| SQLite write contention under load | Medium | Single publish consumer is the only writer; WAL mode allows concurrent reads |
| Message serialization fails for nested RuntimeEvent DTOs | High | Add explicit transport serializer tests before enabling workers |
| In-memory idempotency duplicates events | High | Persist idempotency before multi-worker scaling |
| Feed table grows unbounded | Low | Lifecycle cleanup on terminal + periodic housekeeping |
| `runtime-events.jsonl` removal breaks existing tests | Medium | Keep behind flag initially, update tests to use feed, then remove |
| Command bus becomes async by mistake | High | Explicit routing config; command bus messages never route to Doctrine transport |
| Feed latency too high for streaming UX | Medium | Publish consumer should be lightweight (single INSERT); measure end-to-end latency |

## Recommended task breakdown

1. **ASYNC-00** ~~FrameworkBundle CLI infrastructure~~ ✅ DONE (PR #37)

2. **ASYNC-01 Doctrine SQLite transport + publish bus**
   - Install doctrine-messenger + doctrine-bundle.
   - Configure SQLite DBAL connection.
   - Add `agent.publisher.bus` and four Doctrine transports.
   - Create `PublishRuntimeEvent`, `PublishRuntimeEventWorker`, `RuntimeEventFeedStore`, `MessengerPublishRuntimeEventSink`.
   - Verify with `debug:messenger`.

3. **ASYNC-02 Wire publish sources + feed polling**
   - Wire stream subscribers and RunCommit to publish bus.
   - Add feed-based polling to RuntimeEventPoller.
   - Add feed cleanup on terminal/session start.
   - Deprecate `runtime-events.jsonl`.

4. **ASYNC-03 Protocol ACK and controller skeleton**
   - Add `command_ack` event type.
   - Controller ACK/reject commands.
   - Controller supervises four consumers.

5. **ASYNC-04 Async execution transports**
   - Route LLM/tool messages to async transports.
   - Verify TUI stays responsive during LLM work.

6. **ASYNC-05 Async run-control consumer**
   - Route command bus messages to run_control transport.
   - Validate self-advance callbacks work across transport.

7. **ASYNC-06 Persistent idempotency, CAS retry, supervision**
   - Harden for production multi-process use.

## History

- 2026-05-21: initial plan (no FrameworkBundle, custom wiring)
- 2026-05-21: revised for FrameworkBundle adoption
- 2026-05-21: revised with publish bus + SQLite event feed model (RuntimeEventPoller polls DB directly, no controller relay, lifecycle-bounded cleanup, Mercure as future publish consumer)
