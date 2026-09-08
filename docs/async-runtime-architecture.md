# Async Runtime Architecture

Multi-process runtime topology for interactive Hatfield sessions. See the [architecture guide](../architecture/README.md) for diagrams and cross-cutting navigation.

## Processes and ownership

| Process | Owns | Does not own |
|---|---|---|
| TUI (`InteractiveMode`) | Rendering, user input, polling projected events | Durable run state, Messenger workers |
| Controller (`agent --controller`) | Session-owner lock, consumer supervision, process lifecycle | Translating domain events into UI DTOs |
| `run_control` consumer | `RunState`, canonical event append, command/result transitions | Tool/LLM handler execution |
| LLM / tool / agent / MCP / extension_agent workers | Assigned `Execute*` and side-work messages | Mutating `RunState` directly |
| `scheduler_default` consumer | In-memory Symfony Scheduler recurring tasks | Durable delayed batch deadlines |

The TUI talks to the controller through `AgentSessionClient` (`Runtime/Contract` + `Runtime/Protocol`). Process mode uses JSONL stdin/stdout (`JsonlProcessAgentSessionClient`). In-process mode calls AgentCore directly for tests and simple runs.

## Event and state flow

1. User submits a message. The TUI writes a runtime command through the client.
2. The controller has launched consumers before announcing readiness. It ACKs the command before handler dispatch. Acceptance does not mean execution completed, and dispatch can still fail. Durable transitions land on the `run_control` transport.
3. `run_control` handlers append canonical `RunEvent` values to `events.jsonl` and keep the current `RunState` in the single run-control process memory (plus the payload-free operational projection).
4. LLM and tool workers return result messages to `run_control`. Tool routing also covers subagent and MCP calls. MCP lifecycle commands and extension agent jobs have separate handlers, not a universal `ToolCallResult` return path.
5. `RuntimeEventTranslator` / `RuntimeEventMapper` consume committed `RunEvent` values and produce runtime protocol DTOs. They do **not** read `RunState`.
6. Live observers receive these mapped events on controller stdout. Transient stream deltas use sequence `0` and stay separate from durable replay.
7. `RuntimeEventPoller` (TUI) applies projected events to the screen.

Canonical replay source is the session event log, not transient deltas. See [session-storage.md](session-storage.md).

## Messenger routing (execution bus)

Default YAML routing sends `ExecuteToolCall` to the `tool` transport. Middleware can override the transport before send:

| Tool / message | Transport | Mechanism |
|---|---|---|
| Ordinary tools, including `fork` | `tool` | Default route |
| `subagent`, `agent_resume` | `agent` | `SubagentExecuteToolCallRoutingMiddleware` |
| MCP-backed tool calls | `mcp` | `McpExecuteToolCallRoutingMiddleware` |
| `ExecuteLlmStep`, `ExecuteCompactionStep` | `llm` | YAML routing |
| Extension agent jobs | `extension_agent` | YAML routing |

Deferred subagent batch timeout interruption uses durable `run_control` delivery with `DelayStamp`. That path is not the in-memory scheduler.

## Scheduler versus durable delay

The controller launches `scheduler_default` for Symfony Scheduler work on the `default` schedule. Current recurring owners:

- File-mention completion index refresh every **30s** (`completion:file-index:refresh`).
- Provisional foreground bash supervision cleanup every **300s** (`BackgroundProcessProvisionalCleanupTask`).

Those tasks are generated/in-memory schedule work for the live controller process. Durable deferred-batch deadlines stay on `run_control` with `DelayStamp`.

## Supervision (`ConsumerSupervisor`)

Controller-owned messenger consumers use these invariants:

- Doctrine claim semantics: session Doctrine DSNs set `redeliver_timeout=315360000` (~ten 365-day years) and consumers launch without `--keepalive`. Claimed rows stay unavailable until that finite horizon; rows older than the horizon can reclaim. Restarting the same session reuses the same queue and does not reset `delivered_at` age. Explicit `/repair` redrives current effects as fresh unclaimed envelopes; it does not clear the abandoned claimed row.
- Restart budget: max **3** restarts per consumer key within a **60s** window, initial restart delay **1s**; beyond budget the consumer is abandoned and the controller can surface a diagnostic.
- Shared consumer graceful shutdown grace defaults to **5s**; partial stdout line buffer max **65_536** bytes; stderr tail retained for crash diagnostics (**16_384** bytes).
- Consumer memory recycle threshold **256M** via Messenger worker options.

Other notes:

- `HATFIELD_BINARY_PATH` selects the executable used for subprocesses (PHAR/static/tests).
- Cancellation and shutdown are best-effort across workers. MCP disconnect is best-effort on worker stop ([mcp.md](mcp.md)).
- Extension construction/logger injection/subscriber attachment is not fully isolated today. A throwing constructor can abort startup; tracked separately from this architecture overview.

## Boundaries

- `depfile.yaml` / `castor deptrac` are authoritative. Do not invent blanket bans that Deptrac does not enforce.
- Product TUI may depend on CodingAgent services directly. Session/runtime protocol still uses Runtime Contract/Protocol.
- `/repair` must go through `AgentSessionClient` into the owning controller/runtime. The process-mode TUI parent intentionally lacks `HATFIELD_RUN_CONTROL` / `LLM` / `TOOL` transport DSNs and must not call `SessionRepairService` locally.
- Direct TUI → AgentCore edges exist only where Deptrac explicitly allows them and usually signal misplaced ownership.
- AgentCore must not depend on CodingAgent or TUI.
- Extension feature UX stays in extension packages; runtime ports stay generic.

## Related

- Replay measurements and transition identity matrix: [session-runtime-internals.md](session-runtime-internals.md)
- Process executable resolution: `src/CodingAgent/Runtime/Process/AGENTS.md`
- TUI: [tui-architecture.md](tui-architecture.md)
- Tool execution: [tool-execution.md](tool-execution.md)
- Distribution: [distribution.md](distribution.md)
