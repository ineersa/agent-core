# Async headless runtime and Messenger worker plan

Date: 2026-05-21  
Status: revised after deciding to adopt FrameworkBundle for CLI infrastructure

## Purpose

Make Hatfield's TUI/headless runtime responsive while AgentCore performs slow LLM and tool work.

The simpler target is now to use Symfony FrameworkBundle/Messenger in the CLI app instead of maintaining custom Messenger wiring. The controller process should not become a bespoke queue/worker runtime. It should launch/supervise normal Messenger consumers and translate between TUI JSONL commands and AgentCore messages/events.

Core invariants remain:

- TUI talks only to `AgentSessionClient` / runtime protocol DTOs.
- `src/AgentCore/` remains the canonical run/state/event engine.
- `.hatfield/sessions/<id>/events.jsonl` remains the canonical event log.
- `.hatfield/sessions/<id>/state.json` remains the run-state checkpoint/CAS file.
- `runtime-events.jsonl` and `transcript.jsonl` remain projections/debug/live-transport aids, not canonical history.

## Key correction from the old plan

The old plan assumed we could not use FrameworkBundle, so it proposed custom Messenger transport wiring, custom worker commands, and a smarter headless controller.

That is no longer the preferred path.

With FrameworkBundle:

- use normal `framework.messenger` configuration;
- use normal `bin/console messenger:consume ...` worker processes;
- route messages by class to separate transports;
- let consumers do orchestration, LLM calls, and tool calls;
- keep the controller process focused on JSONL protocol, ACKs, event forwarding, and consumer supervision.

This should dramatically reduce custom infrastructure.

## Target process topology

```text
TUI process
  - owns terminal rendering/input
  - sends JSONL RuntimeCommand
  - reads JSONL RuntimeEvent / command_ack
        |
        v
Headless controller process
  - nonblocking stdin/stdout JSONL loop
  - validates and ACKs commands quickly
  - dispatches StartRun / ApplyCommand into Messenger
  - tails/polls canonical/projection events and forwards RuntimeEvent JSONL
  - launches/monitors Messenger consumers
  - does NOT run AgentCore LLM/tool work inline
        |
        v
Messenger transports / consumers
  run_control consumer
    - StartRun
    - ApplyCommand
    - AdvanceRun
    - LlmStepResult
    - ToolCallResult
    - owns canonical state transitions/commits

  llm consumer
    - ExecuteLlmStep
    - invokes Symfony AI / provider stream
    - dispatches LlmStepResult back to run_control

  tool consumer
    - ExecuteToolCall
    - executes tool subprocesses/callables
    - dispatches ToolCallResult back to run_control
```

Initial consumer count:

```bash
bin/console messenger:consume run_control
bin/console messenger:consume llm
bin/console messenger:consume tool
```

The controller may start exactly one consumer per transport at first. Scaling to multiple tool/LLM workers is a later step after idempotency and CAS retry are hardened.

## Message routing model

### Logical buses

AgentCore already has two logical buses:

- `agent.command.bus` for orchestration/control messages.
- `agent.execution.bus` for expensive execution messages.

With FrameworkBundle, keep the two buses if they remain useful internally, but route by message class to transports:

```text
run_control transport:
  Ineersa\AgentCore\Domain\Message\StartRun
  Ineersa\AgentCore\Domain\Message\ApplyCommand
  Ineersa\AgentCore\Domain\Message\AdvanceRun
  Ineersa\AgentCore\Domain\Message\LlmStepResult
  Ineersa\AgentCore\Domain\Message\ToolCallResult

llm transport:
  Ineersa\AgentCore\Domain\Message\ExecuteLlmStep

tool transport:
  Ineersa\AgentCore\Domain\Message\ExecuteToolCall
```

The exact bus/transport mapping should be validated during implementation. The important property is process separation:

- command-reading controller path must not block on LLM/tool execution;
- LLM worker must not directly mutate canonical state except through result messages;
- tool worker must not directly mutate canonical state except through result messages;
- run-control worker is the primary owner of `state.json` / `events.jsonl` commits.

### Result routing

Current workers dispatch results back to `MessageBusInterface $commandBus`:

- `ExecuteLlmStepWorker` dispatches `LlmStepResult`.
- `ExecuteToolCallWorker` dispatches `ToolCallResult`.

In async mode, those result dispatches should enqueue onto the run-control path, not synchronously recurse in the LLM/tool worker process unless intentionally configured for an early spike.

## Controller responsibilities

The controller process should be boring.

It may:

- launch and restart `messenger:consume run_control`, `llm`, and `tool` child processes;
- read TUI JSONL commands without blocking on agent execution;
- emit `command_ack` / rejected ACK quickly;
- dispatch valid commands to Messenger;
- forward runtime events to stdout JSONL by tailing/polling session files or a projection stream;
- capture child stderr/stdout into logs for debugging;
- escalate hard-cancel by stopping a worker process only after graceful cancellation times out.

It should not:

- perform LLM calls;
- execute tools;
- manually process AgentCore state transitions;
- directly mutate canonical `state.json` except for narrowly-scoped session metadata/bootstrap if unavoidable;
- become a replacement for Messenger consumers.

For normal prompts, the controller should dispatch messages and let run-control consumers update canonical state:

```text
controller receives start/follow_up/steer/cancel
  -> ACK command
  -> dispatch StartRun or ApplyCommand
  -> run_control consumer loads state.json/events.jsonl
  -> RunCommit writes canonical state/events
```

## Storage and state sharing

### Canonical files

- `.hatfield/sessions/<id>/events.jsonl`
  - canonical append-only AgentCore domain event stream
  - written by `SessionRunEventStore`
  - source for replay and durable projections

- `.hatfield/sessions/<id>/state.json`
  - materialized `RunState` checkpoint
  - written through `SessionRunStore::compareAndSwap()`
  - read by workers for context and cancellation checks

- `.hatfield/sessions/<id>/metadata.yaml`
  - canonical session identity/tree/metadata

### Projection / transport files

- `.hatfield/sessions/<id>/transcript.jsonl`
  - user-facing transcript projection
  - rebuildable from canonical events

- `.hatfield/sessions/<id>/runtime-events.jsonl`
  - runtime protocol projection/debug/live transport aid
  - useful for controller-to-TUI forwarding
  - not canonical replay history

### Do consumers already read state?

Mostly yes:

- `LlmPlatformAdapter` resolves context from `RunStore` using `runId` from `ExecuteLlmStep`.
- tool execution already receives `runId` and can use `RunStore` for cancellation through `RunCancellationToken`.
- run-control handlers load/commit `RunState` through the session stores.

Therefore the main missing pieces are not “how do workers see state?” but:

- process-safe idempotency;
- CAS retry/backoff;
- transport serialization;
- process-safe transient runtime event streaming;
- reliable consumer supervision.

## Runtime protocol changes

Add generic command acknowledgements.

Current `RuntimeCommand` already has an `id`. The controller should echo it quickly:

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

Rejected commands use the same event type:

```json
{
  "type": "command_ack",
  "payload": {
    "commandId": "cmd_...",
    "status": "rejected",
    "reason": "run is terminal"
  }
}
```

Recommended additions:

- `RuntimeEventTypeEnum::CommandAck`
- optional `cmdRef` / `commandId` payload convention for events caused by a command
- `ping` / `pong` command-event pair for controller health

## Streaming/runtime event projection

The old outbox/publisher idea may be worth reintroducing narrowly.

Do **not** resurrect the old generic Flysystem/Mercure outbox stack blindly. But a small durable projection/publisher path could be useful:

```text
canonical events.jsonl
  -> projector/publisher worker
  -> runtime-events.jsonl and/or controller stdout JSONL
```

Options, in increasing sophistication:

1. controller tails `events.jsonl` and maps to runtime events itself;
2. run-control consumer writes `runtime-events.jsonl` as a projection after commits;
3. dedicated publisher transport/consumer projects domain events to runtime events;
4. later, replace file tailing with a DB/outbox table if persistence moves off JSONL.

For the first implementation, prefer the least custom path that works. If controller-side event tailing becomes too stateful, add a narrow publisher/projection consumer.

Streaming deltas are special:

- LLM deltas are emitted by the LLM worker, not by run-control commits.
- `InMemoryRuntimeEventSink` will not cross process boundaries.
- Process mode needs a file/stdout/transport-backed runtime event sink.

Likely first cut:

- LLM/tool workers append transient runtime events to `runtime-events.jsonl` with a transient marker such as `seq=0` or separate stream IDs;
- controller tails `runtime-events.jsonl` and forwards to TUI;
- canonical completed messages still land in `events.jsonl` through `LlmStepResult` / `ToolCallResult`.

## FrameworkBundle/Messenger setup assumptions

The FrameworkBundle adoption task should establish:

- FrameworkBundle registered for CLI/container infrastructure;
- no HTTP controllers/routes/public index;
- normal `framework.messenger` config;
- `bin/console messenger:consume` available;
- `debug:messenger` available if supported;
- MonologBundle configured for structured app logs.

After that, async runtime tasks should use Symfony-native Messenger features instead of custom worker commands where possible.

Possible local/dev transports:

- Doctrine transport if dependency/config is acceptable;
- Redis transport if dependency/config is acceptable;
- filesystem/SQLite/custom transport only if standard transports are unsuitable;
- in-memory/sync only for tests, not for responsiveness validation.

## Required hardening before multi-process is safe

| Area | Current risk | Target |
|---|---|---|
| Idempotency | `MessageIdempotencyService` is in-memory | persistent per-session or transport-backed idempotency store |
| CAS conflicts | failed `compareAndSwap()` can drop progress | retry/backoff or explicit retryable worker failure |
| Runtime stream | `InMemoryRuntimeEventSink` is per-process | file/stdout/transport-backed sink |
| Serialization | some result payloads include DTO/value objects | serializer round-trip tests for every transported message |
| Supervision | `AgentProcessSupervisor` is scaffold-level | start/restart/heartbeat/log capture for consumers |
| Cancellation | graceful cancellation depends on worker checks | ACK immediately, set cancellation state, escalate hard kill later |

## Implementation phases

### Phase 0 — FrameworkBundle/Messenger foundation

Goal: stop maintaining custom Messenger infrastructure.

Tasks:

- adopt FrameworkBundle for CLI/container infrastructure;
- configure `framework.messenger` buses/transports;
- expose `messenger:consume` / `debug:messenger`;
- configure Monolog via MonologBundle;
- update repository policy docs: FrameworkBundle allowed for CLI infra, HTTP stack still disallowed.

Acceptance criteria:

- `bin/console list` shows Messenger commands;
- `bin/console debug:messenger` shows handlers/buses/transports;
- `castor deptrac`, `castor test`, `castor phpstan`, `castor cs-check` pass;
- product-level TUI/headless smoke still works.

### Phase 1 — command ACK and controller skeleton

Goal: separate command receipt from command execution at the protocol level.

Tasks:

- add `command_ack` runtime event type;
- make headless controller ACK valid JSONL commands before dispatching Messenger messages;
- reject invalid commands with rejected ACK/visible error event;
- add heartbeat if simple;
- controller can still use sync transports for this phase, but the ACK behavior must be explicit and testable.

Acceptance criteria:

- parent can correlate every command ID to an ACK;
- ACK is emitted before long-running work completes once async transports are enabled;
- existing in-process TUI behavior remains unchanged.

### Phase 2 — async execution transports

Goal: move slow LLM/tool work out of the run-control path.

Tasks:

- route `ExecuteLlmStep` to `llm` transport;
- route `ExecuteToolCall` to `tool` transport;
- route `LlmStepResult` / `ToolCallResult` back to `run_control`;
- controller launches one `messenger:consume llm` and one `messenger:consume tool`;
- keep run-control single-consumer at first.

Acceptance criteria:

- `AgentRunner::start()`/controller command handling returns quickly after scheduling execution;
- LLM/tool work runs in separate consumer process(es);
- canonical `events.jsonl` and `state.json` remain correct;
- TUI/headless command loop receives steer/cancel while LLM work is in progress.

### Phase 3 — async run-control consumer

Goal: make orchestration itself a proper consumer path.

Tasks:

- route `StartRun`, `ApplyCommand`, `AdvanceRun`, `LlmStepResult`, and `ToolCallResult` to `run_control`;
- controller dispatches start/follow-up/steer/cancel and returns to JSONL loop;
- ensure self-advance callbacks enqueue correctly rather than requiring same-stack recursion;
- validate terminal/stale-result/idempotent behavior.

Acceptance criteria:

- controller never blocks on run-control processing;
- run-control consumer can be restarted without corrupting state;
- one run progresses from start to LLM to result to completion through transports.

### Phase 4 — process-safe runtime event stream

Goal: keep the TUI visibly live while work happens in other processes.

Tasks:

- replace process-mode `InMemoryRuntimeEventSink` with file/stdout/transport-backed sink;
- decide whether controller tails `events.jsonl`, tails `runtime-events.jsonl`, or consumes a publisher/projection transport;
- preserve transient-vs-canonical distinction;
- keep session artifacts useful on failure.

Acceptance criteria:

- TUI shows assistant/thinking/tool deltas from a worker process;
- completed canonical events still replay from `events.jsonl`;
- `runtime-events.jsonl` is not required for replay correctness.

### Phase 5 — persistent idempotency, CAS retry, and supervision

Goal: make the multi-process topology robust.

Tasks:

- persist idempotency keys;
- make CAS conflicts retryable or explicitly non-retryable;
- supervise `run_control`, `llm`, and `tool` consumers;
- capture stderr/stdout logs;
- add heartbeat/restart policy;
- add hard-cancel escalation.

Acceptance criteria:

- duplicate command/result messages do not duplicate canonical events;
- worker restart does not lose queued work;
- cancel ACKs quickly and either gracefully cancels or escalates;
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

Use `castor test:tui` when changing TUI snapshots/e2e behavior, and `castor test:llm-real` for real model paths.

Product validation must exercise:

1. start agent in tmux;
2. type prompt;
3. submit;
4. wait for visible assistant response or visible error block;
5. send steer/cancel during in-flight work for async slices;
6. capture TUI snapshot and session artifacts on failure:
   - `events.jsonl`
   - `runtime-events.jsonl`
   - `transcript.jsonl`
   - `state.json`

Suggested new tests:

- `debug:messenger`/container test shows routed handlers/transports;
- serializer round-trip for all messages crossing async transports;
- command ACK emitted before fake slow LLM worker completes;
- execution transport dispatch does not block controller command loop;
- cancel during slow/fake LLM updates state/emits cancellation runtime event;
- duplicate worker result is idempotent;
- CAS conflict triggers retry/backoff.

## Risks and mitigations

| Risk | Impact | Mitigation |
|---|---|---|
| FrameworkBundle drags in HTTP assumptions | Medium/High | Allow CLI/container infra only; forbid public index/controllers/router unless separately approved |
| Message routing causes recursive sync behavior | High | Validate with process-level test and `debug:messenger`; ensure LLM/tool/result classes route to transports |
| Message serialization fails for nested DTOs | High | Add explicit transport serializer tests before enabling workers |
| In-memory idempotency duplicates events | High | Persist idempotency before multi-worker scaling |
| Streaming deltas lost across process boundary | Medium/High | Add process-safe runtime event sink before relying on worker streaming |
| CAS failure silently drops messages | High | Convert transient CAS conflicts to retryable worker failures |
| `runtime-events.jsonl` becomes accidental source of truth | Medium | Keep replay from `events.jsonl`; document projection status |
| Operational complexity grows too early | Medium | Start with one controller and one consumer per transport |

## Recommended task breakdown

1. **ASYNC-00 FrameworkBundle CLI infrastructure**
   - Adopt FrameworkBundle/Messenger/MonologBundle.
   - Remove custom Messenger compiler pass.
   - Establish normal `messenger:consume` workflow.

2. **ASYNC-01 Protocol ACK and controller skeleton**
   - Add `command_ack` event.
   - Add controller ACK/heartbeat behavior.
   - Measure current vs async command latency.

3. **ASYNC-02 Async transport routing spike**
   - Route run-control, LLM, and tool messages to transports.
   - Launch one consumer for each transport.
   - Prove LLM/tool work no longer blocks command intake.

4. **ASYNC-03 Process-safe runtime event sink/projection**
   - Make worker stream deltas visible to TUI across processes.
   - Decide whether a narrow publisher/projection consumer is needed.

5. **ASYNC-04 Persistent idempotency and CAS retry**
   - Make duplicate delivery and concurrent commits safe.

6. **ASYNC-05 Consumer supervision and hard cancel**
   - Controller manages run-control/llm/tool consumers.
   - Add restart/log/heartbeat/hard-kill policy.

## Scout notes

This plan is based on scout reconnaissance run on 2026-05-21 and subsequent architecture decisions in the same session.

Important conclusions:

- AgentCore is already architected for command vs execution separation.
- FrameworkBundle makes the worker plan much simpler than custom no-FrameworkBundle wiring.
- Three initial consumers are enough: run-control, LLM, tool.
- Workers can read shared state from session files; the harder problems are idempotency, CAS retry, serialization, and runtime event streaming.
- `events.jsonl` is the canonical cross-process event log.
- `runtime-events.jsonl` should remain projection/debug/live transport, not canonical replay.
- The controller must not be the process that blocks on LLM/tool work.
