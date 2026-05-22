# Async headless runtime and Messenger worker plan

Date: 2026-05-21

## Purpose

Make Hatfield's TUI/headless runtime responsive while AgentCore performs slow LLM and tool work.

The original AgentCore design already contains the right split: orchestration commands are modeled as Messenger messages and expensive execution is separated behind an execution bus. The current flaw is that all buses are wired as synchronous in-process `MessageBus` services, so `AgentRunner::start()` can still run the whole LLM/tool loop before returning.

This plan describes how to restore the intended async shape without violating the repository boundaries:

- TUI talks only to `AgentSessionClient` / runtime protocol DTOs.
- `src/AgentCore/` remains the canonical run/state/event engine.
- `.hatfield/sessions/<id>/events.jsonl` remains the canonical event log.
- `.hatfield/sessions/<id>/state.json` remains the run-state checkpoint/CAS file.
- runtime/transcript JSONL files remain projections or transport aids, not canonical history.

## Current state

### Existing async seams

AgentCore already has two logical buses:

- `agent.command.bus`
  - `StartRun`
  - `AdvanceRun`
  - `ApplyCommand`
  - `LlmStepResult`
  - `ToolCallResult`

- `agent.execution.bus`
  - `ExecuteLlmStep`
  - `ExecuteToolCall`

Relevant files:

- `config/packages/messenger.yaml`
- `src/CodingAgent/Integration/MessengerIntegrationCompilerPass.php`
- `src/AgentCore/Application/Pipeline/AgentRunner.php`
- `src/AgentCore/Application/Pipeline/RunOrchestrator.php`
- `src/AgentCore/Application/Pipeline/RunMessageProcessor.php`
- `src/AgentCore/Application/Handler/StepDispatcher.php`
- `src/AgentCore/Application/Handler/ExecuteLlmStepWorker.php`
- `src/AgentCore/Application/Handler/ExecuteToolCallWorker.php`

The key seam is:

```text
AdvanceRunHandler
  -> HandlerResult effects: ExecuteLlmStep / ExecuteToolCall
  -> RunCommit
  -> StepDispatcher
  -> agent.execution.bus
```

Today `agent.execution.bus` is synchronous, so dispatching an `ExecuteLlmStep` immediately invokes `ExecuteLlmStepWorker`, which blocks on `LlmPlatformAdapter::invoke()`.

### Current headless/process transport

Existing process runtime files:

- `src/CodingAgent/Runtime/Process/JsonlProcessAgentSessionClient.php`
- `src/CodingAgent/Runtime/Process/AgentProcessSupervisor.php`
- `src/CodingAgent/Runtime/Process/JsonlRuntimeEventSink.php`
- `src/CodingAgent/CLI/AgentCommand.php` (`--headless`)

The process transport is useful but not sufficient yet. The subprocess reads a JSONL command from stdin, then runs the in-process client synchronously. While it is blocked in LLM/tool work, it cannot read the next command, so steer/cancel are only observed after the current work completes.

Current blocking shape:

```text
TUI process
  -> JsonlProcessAgentSessionClient writes JSONL command
  -> bin/console agent --headless
    -> fgets(stdin)
    -> handleHeadlessStart()/handleHeadlessMessage()
      -> InProcessAgentSessionClient
        -> AgentRunner
          -> synchronous command bus
            -> synchronous execution bus
              -> LLM/tool work blocks
```

## Storage model

### Canonical files

- `.hatfield/sessions/<id>/events.jsonl`
  - canonical append-only AgentCore domain event stream
  - written by `SessionRunEventStore`
  - read by replay, runtime mapping, projections

- `.hatfield/sessions/<id>/state.json`
  - materialized `RunState` checkpoint
  - written via `SessionRunStore::compareAndSwap()`
  - useful for cross-process run status / cancellation checks

- `.hatfield/sessions/<id>/metadata.yaml`
  - canonical session identity/tree/metadata

### Projection / debug / transport files

- `.hatfield/sessions/<id>/transcript.jsonl`
  - user-facing transcript projection
  - rebuildable from canonical events

- `.hatfield/sessions/<id>/runtime-events.jsonl`
  - runtime protocol projection/debug log
  - useful for polling or diagnostics
  - not canonical replay history

For the async design, `events.jsonl` should be the source of truth. `runtime-events.jsonl` may be used as a simple local transport/projection for the TUI/headless controller, but it should remain rebuildable and disposable.

## Target architecture

```text
TUI process
  - owns terminal rendering and input
  - sends JSONL RuntimeCommand
  - reads JSONL RuntimeEvent / command_ack
        |
        v
Headless controller process
  - nonblocking stdin/stdout loop
  - acks commands quickly
  - dispatches StartRun / ApplyCommand into runtime
  - tails/polls runtime/domain events and forwards RuntimeEvent JSONL
  - never performs LLM/tool work inline
        |
        v
AgentCore orchestration
  - command bus processes run state transitions
  - writes state.json and events.jsonl
  - emits effects for slow work
        |
        v
Execution workers
  - LLM worker consumes ExecuteLlmStep
  - tool worker consumes ExecuteToolCall
  - dispatches LlmStepResult / ToolCallResult back to command bus
```

Desired responsiveness:

- start/follow-up/steer/cancel command accepted: ~10-100 ms
- visible command ACK in TUI: next poll/tick
- steer applied: next safe AgentCore boundary between LLM/tool turns
- graceful cancel: as soon as the running worker checks cancellation; hard-kill remains a later process-supervision option

## Core design decisions

### 1. Keep command semantics separate from presentation state

The command router should use explicit run activity state, not TUI text such as `workingMessage`.

- idle/completed/failed/cancelled -> next user text is `follow_up`
- starting/running/waiting_human/cancelling -> next user text is `steer` or HITL answer depending on current runtime state

RTVS-11 adds the first version of this TUI activity state.

### 2. Make expensive work async before making everything async

The lowest-risk restoration of the original architecture is to async only the execution bus first:

```text
agent.command.bus: synchronous inside one orchestration process
agent.execution.bus: async transport consumed by LLM/tool workers
```

This preserves the existing self-advancing command-bus callbacks while moving blocking LLM/tool work out of the command-reading path.

Only after storage/idempotency/retry are hardened should `agent.command.bus` itself become fully async.

### 3. Do not make `runtime-events.jsonl` canonical

`runtime-events.jsonl` can be a projection or local transport. The canonical event stream is `events.jsonl`. Session replay should rebuild from `events.jsonl`, not from runtime stream deltas.

Streaming deltas are transient and user-visible. Completed assistant/tool results should eventually be represented by canonical AgentCore events.

### 4. Do not depend on FrameworkBundle messenger config

This app intentionally does not use FrameworkBundle. Current `config/packages/messenger.yaml` defines raw DI services, and `MessengerIntegrationCompilerPass` wires middleware/handlers.

So async transport support must be implemented in the current HTTP-less app style, either by:

- manually wiring Symfony Messenger senders/receivers/workers, or
- adding a small project-specific worker command around the existing message bus and chosen transport, or
- introducing only the required Symfony Messenger components, not FrameworkBundle.

Archive docs mention `messenger:consume`, but the current app may not expose that command until worker/receiver services are explicitly wired.

## Required infrastructure changes

### Shared/persistent services

Before multi-process workers are safe, replace or harden these in-memory services:

| Current service | Problem | Target |
|---|---|---|
| `InMemoryCommandStore` | pending command queue is per-process | file/SQLite/transport-backed command queue |
| `MessageIdempotencyService` | handled idempotency keys are per-process | persistent idempotency key store |
| `HotPromptStateStore` | hot prompt state is per-process | file-backed prompt state or rebuild-on-demand cache |
| `InMemoryRuntimeEventSink` | stream deltas vanish across processes | JSONL/stdout/file-backed runtime event sink for process mode |

Potential session-local files:

```text
.hatfield/sessions/<id>/
  pending-commands.jsonl       # optional shared command queue
  handled-messages.jsonl       # idempotency keys
  prompt-state.json            # optional hot prompt cache
```

### CAS/retry behavior

`SessionRunStore::compareAndSwap()` is useful cross-process protection, but a failed CAS currently means the message is effectively dropped. In async mode, CAS failure should cause a controlled retry/backoff, not silent loss.

Required work:

- identify all `RunCommit::commit()` false-return paths;
- ensure async message handlers throw/retry on transient CAS conflict;
- keep terminal/stale-result checks idempotent and non-retryable where appropriate.

### Message serialization

Transporting only `ExecuteLlmStep` and `ExecuteToolCall` is easier than transporting every command/result type, but serialization still needs validation.

Risk areas:

- `LlmStepResult` payloads may contain Symfony AI DTO/value objects.
- `AgentMessage` and tool result payloads must round-trip exactly.
- serializer config must include array and phpdoc/property-info support.

Initial tests should round-trip all Messenger message classes that cross a process/transport boundary.

## Runtime protocol changes

Add generic command acknowledgements.

Current `RuntimeCommand` already has an `id`. The headless/controller process should echo it quickly:

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

Also support rejected ACKs:

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
- optional `cmdRef` or `commandId` payload convention for events caused by a command
- `ping` / `pong` command-event pair for process health

## Implementation phases

### Phase 0 — document and prove current blocking behavior

Goal: make the failure mode explicit and measurable.

Tasks:

- Add a short architecture note or test fixture showing that `--headless` currently blocks while handling a start/user command.
- Add a product-level latency target for command ACK/cancel responsiveness.
- Capture baseline with `castor run:agent-test` or a process transport smoke.

Acceptance criteria:

- A prompt can be submitted in process/headless mode.
- A second command sent while LLM work is running is shown to be queued or delayed today.
- Baseline timings are recorded in the task notes.

### Phase 1 — protocol ACK and nonblocking headless controller skeleton

Goal: separate command receipt from command execution at the protocol level.

Tasks:

- Add `command_ack` runtime event type.
- Make `AgentCommand --headless` acknowledge valid JSONL commands immediately before dispatching work.
- Ensure invalid commands produce rejected ACK or visible error event.
- Add heartbeat support if simple.

Acceptance criteria:

- Parent can correlate every command ID to an ACK.
- ACK appears before the long-running LLM/tool operation completes.
- Existing in-process TUI behavior remains unchanged.

### Phase 2 — async execution bus spike

Goal: make `ExecuteLlmStep` and `ExecuteToolCall` leave the command-reading/orchestration path.

Tasks:

- Introduce an async transport abstraction compatible with the no-FrameworkBundle app.
- Wire `agent.execution.bus` with sender middleware for:
  - `ExecuteLlmStep`
  - `ExecuteToolCall`
- Add a worker/consumer command for the execution bus.
- Keep `agent.command.bus` synchronous inside the orchestration process for this phase.
- Ensure worker results dispatch back as `LlmStepResult` / `ToolCallResult` into the orchestrator path.

Open design question for this phase:

- Does the execution worker dispatch result messages back over an async command transport, or does a controller/orchestrator process expose a result channel? The simplest first slice may run a single orchestration process and an execution worker process connected by a local transport.

Acceptance criteria:

- `AgentRunner::start()` returns quickly after scheduling `ExecuteLlmStep`.
- LLM/tool work runs in a separate worker/consumer process.
- Canonical `events.jsonl` and `state.json` are still written correctly.
- TUI/headless command loop can receive a cancel/steer command while LLM work is in progress.

### Phase 3 — shared runtime event sink

Goal: stream user-visible runtime events across processes.

Tasks:

- Decide process-mode stream sink:
  - stdout JSONL from worker/controller, or
  - append-only `runtime-events.jsonl` projection tailed by controller, or
  - both, with stdout as live transport and file as debug artifact.
- Replace `InMemoryRuntimeEventSink` for process/worker mode.
- Ensure stream deltas use `seq=0` or another explicit transient marker.
- Keep completed canonical events in `events.jsonl`.

Acceptance criteria:

- TUI shows streaming assistant text/thinking/tool deltas when execution runs in another process.
- `runtime-events.jsonl` is not required for replay correctness.
- Session artifacts remain useful on failure.

### Phase 4 — persistent idempotency and command/prompt stores

Goal: make multi-process command/result handling safe.

Tasks:

- Add persistent `MessageIdempotencyService` implementation.
- Add shared `CommandStoreInterface` implementation or replace command queue behavior with transport-backed messages.
- Add file-backed or rebuild-on-demand prompt-state cache.
- Make CAS conflict handling retryable.

Acceptance criteria:

- Duplicate command/result messages do not produce duplicate canonical events.
- CAS conflicts are retried or surfaced explicitly, not silently dropped.
- A worker restart can continue from `events.jsonl`/`state.json` without losing queued commands.

### Phase 5 — fully async command bus and process supervision

Goal: support a robust production-ish topology.

Tasks:

- Move selected command-bus messages to async transport if needed.
- Add run/orchestrator consumer process.
- Add LLM and tool consumer process groups.
- Teach `AgentProcessSupervisor` to manage controller/worker lifecycles.
- Add heartbeat, stderr capture, restart, and hard-cancel escalation.

Potential process groups:

```bash
bin/console agent --headless-controller
bin/console agent:worker command --limit=... --memory-limit=...
bin/console agent:worker llm --limit=... --memory-limit=...
bin/console agent:worker tool --limit=... --memory-limit=...
```

Acceptance criteria:

- TUI remains responsive under slow LLM/tool calls.
- Cancel command is ACKed quickly and either gracefully cancels or escalates to hard worker termination.
- Multiple runs do not corrupt session state/events.
- All workers can be restarted without losing canonical history.

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

- serializer round-trip for all messages crossing async transport;
- execution-bus async dispatch does not block `AgentRunner::start()`;
- command ACK emitted before LLM worker completes;
- cancel command during slow/fake LLM updates `state.json` / emits cancellation runtime event;
- duplicate worker result is idempotent;
- CAS conflict triggers retry/backoff.

## Risks and mitigations

| Risk | Impact | Mitigation |
|---|---|---|
| Async transport requires FrameworkBundle assumptions | High | Wire Messenger components manually in existing no-FrameworkBundle style |
| Message serialization fails for nested DTOs | High | Add explicit transport serializer tests before enabling workers |
| In-memory idempotency causes duplicate events | High | Persist idempotency before multi-process command/result consumers |
| Streaming deltas lost across process boundary | Medium/High | Add process-safe runtime event sink before moving LLM worker out of process |
| CAS failure silently drops messages | High | Convert transient CAS conflicts to retryable worker failures |
| `runtime-events.jsonl` becomes accidental source of truth | Medium | Document and enforce `events.jsonl` as canonical replay source |
| Operational complexity grows too early | Medium | Start with async execution bus only; defer full command-bus async |

## Recommended next task breakdown

1. **ASYNC-01 Protocol ACK and headless latency baseline**
   - Add `command_ack` event.
   - Measure current delay while a slow fake/real LLM is running.

2. **ASYNC-02 Execution bus transport spike**
   - Manually wire async send/receive for `agent.execution.bus` in HTTP-less app.
   - Add worker command.

3. **ASYNC-03 Process-safe runtime event sink**
   - Stream deltas from worker/controller to TUI without relying on in-memory sink.

4. **ASYNC-04 Persistent idempotency and CAS retry**
   - Make cross-process processing safe.

5. **ASYNC-05 Controller/worker supervision**
   - Promote `AgentProcessSupervisor` from scaffold to real lifecycle manager.

## Scout notes

This plan is based on scout reconnaissance run on 2026-05-21. Raw output was saved by pi at:

```text
/home/ineersa/.pi/agent/tmp/2026-05--f3a0cc48.txt
```

Important scout conclusions:

- AgentCore is already architected for command vs execution separation.
- `agent.execution.bus` is the best first async seam.
- `events.jsonl` is appropriate as canonical cross-process event log.
- `runtime-events.jsonl` should remain projection/debug/live transport, not canonical replay.
- Current headless mode blocks because command reading and LLM/tool execution share the same synchronous call path.
