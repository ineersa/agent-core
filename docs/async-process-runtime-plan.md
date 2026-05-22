# Async / Process Runtime Plan

**Status:** Draft / Design Document  
**Context:** RTVS-11 AC4 — produced after design discussion confirming TUI and
agent runtime must be separated to keep TUI responsive during synchronous LLM/tool
execution.

## Problem

The current in-process transport (`InProcessAgentSessionClient`) runs the agent
loop synchronously inside the same PHP process as the TUI. While the LLM is
streaming or a tool is executing, the TUI event loop cannot process input or
re-render. This is acceptable only for short requests in development.

Long-term, the TUI must stay responsive to:
- Accept and ack `steer` / `cancel` commands within 20–100ms
- Show real-time streaming deltas in the transcript
- Handle human-in-the-loop (HITL) input while the agent blocks

## Target Architecture

```
┌─────────────────────┐    stdin (JSONL)    ┌──────────────────────────┐
│   TUI Process       │ ──────────────────→ │  Headless Control Process │
│  (php-tui,          │                     │  (bin/console agent      │
│   event loop,       │ ←────────────────── │   --headless)            │
│   rendering)        │   stdout (JSONL)    │                          │
│                     │                     │  ┌────────────────────┐  │
│  - polls events     │                     │  │  Command Mailbox   │  │
│  - writes commands  │                     │  │  (in-memory queue) │  │
│  - renders blocks   │                     │  └────────────────────┘  │
└─────────────────────┘                     │           │              │
                                            │           ▼              │
                                            │  ┌────────────────────┐  │
                                            │  │  Worker Runtime    │  │
                                            │  │  (AgentRunner,     │  │
                                            │  │   tools, LLM)     │  │
                                            │  │                    │  │
                                            │  │  - runs agent loop │  │
                                            │  │  - drains mailbox  │  │
                                            │  │    at safe points  │  │
                                            │  │  - emits events    │  │
                                            │  └────────────────────┘  │
                                            └──────────────────────────┘
```

## Key Design Decisions

### Decision 1: Two-process (control + worker) vs single-process with subprocess isolation

**Chosen:** Two processes — headless control process manages its own worker
execution. Rationale: keeps stdin/stdout protocol simple (one event stream per
process), avoids managing subprocess pools, and matches the existing
`JsonlProcessAgentSessionClient` + `AgentCommand --headless` pattern.

### Decision 2: JSONL protocol (existing format)

Reuse the existing `RuntimeCommand` / `RuntimeEvent` JSONL codec.
Extend the protocol with:
- `command_accepted` event — emitted immediately when a command is received,
  before execution begins
- `heartbeat` events — periodic liveness markers

### Decision 3: Command mailbox in the control process

The control process reads stdin commands and places them in an in-memory queue.
The worker checks this queue at safe boundaries (between LLM turns, between tool calls,
before the next LLM call). This keeps command ack latency low regardless of
whether the worker is currently blocking.

## Latency / Responsiveness Targets

| Operation | Target latency | Mechanism |
|-----------|---------------|-----------|
| Command ack (accept/reject) | < 50ms | Control process reads stdin, writes `command_accepted` immediately |
| Steer visibility in transcript | < 500ms | Worker applies steer at next safe boundary, emits events → TUI polls |
| Graceful cancel | < 2s | Cancel token propagated to LLM/tool at next yield point |
| Hard cancel | < 1s | Control process sends SIGTERM/SIGKILL to worker after timeout |
| Heartbeat loss detection | < 5s | Control process expects heartbeat every 1s; if 3 missed → restart worker |

## Cancel Ladder

1. **Queue cancel command** → control process writes `command_accepted` to TUI
2. **Cancellation token** → control process writes `cancellation.requested` event,
   which the worker checks at safe boundaries
3. **Graceful timeout** (2s) → if worker hasn't emitted `run.cancelled`, escalate
4. **SIGTERM** → control process sends SIGTERM to worker subprocess
5. **SIGKILL** (after 3s) → if worker still alive, send SIGKILL
6. **Restart** → control process spawns new worker, run is marked failed

## Steer Flow

1. TUI sends `user_message` (steer) to control process stdin
2. Control process writes `command_accepted` + `user.message_submitted` to stdout
3. Control process appends message to command mailbox queue
4. At the next safe boundary, the worker drains the mailbox:
   - If mid-turn: current LLM call completes, tool call completes, then steer
     messages are applied as additional context for the next LLM call
   - If at turn boundary: steer messages are prepended to the next turn
5. Worker resumes, LLM sees steer message, emits events
6. TUI polls and renders

## Implementation Phases

### Phase 1: Unidirectional JSONL transport (current state)
- Already done: `JsonlProcessAgentSessionClient` + `AgentCommand --headless`
- Missing: `command_accepted` events, heartbeat, restart logic

### Phase 2: Responsive control loop
- Refactor `handleHeadlessStart()` / `handleHeadlessMessage()` / `handleHeadlessCancel()`
  to use a proper event loop that reads stdin while the worker runs
- Implement command mailbox in headless mode
- Add `command_accepted` event emission
- Add heartbeat events from the worker

### Phase 3: Worker isolation
- Spawn worker as a subprocess from the control process (not just calling in-process
  directly from the same PHP process)
- Worker runs `AgentCore` services
- Worker communicates back via stdout (events)
- Worker receives commands via stdin or a shared mailbox mechanism

### Phase 4: Cancel ladder + restart
- Implement the cancel ladder (token → graceful → SIGTERM → SIGKILL)
- Implement health checks and worker restart on crash

### Phase 5: TUI switch to process transport
- Make `--transport=process` the default in `AgentCommand::runTui()`
- Remove or deprecate in-process path for interactive use
- Verify `castor run:agent-test` and `castor test:llm-real` with process transport

## Open Questions / Risks

- **Symfony AI blocking HTTP**: Even with process separation, the worker's LLM
  call blocks until the full response is received. For streaming, the worker must
  emit events between stream chunks. This requires a non-blocking or yield-based
  LLM adapter in the worker.
- **PHP async limitations**: PHP lacks native async I/O. Subprocess communication
  relies on non-blocking streams with polling (select/stream_select). This works
  for process separation but adds complexity.
- **PHAR distribution**: The headless binary location needs `SelfExecutableLocator`
  (see `src/CodingAgent/Runtime/Process/AGENTS.md`). The current hard-coded
  `bin/console` path won't work in a PHAR build.
