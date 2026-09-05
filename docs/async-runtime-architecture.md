# Async Runtime Architecture

Multi-process runtime topology for interactive Hatfield sessions.

## Processes

| Process | Role |
|---|---|
| TUI (`InteractiveMode`) | Renders UI, sends runtime commands, polls events |
| Controller (`agent --controller`) | Owns the run, schedules work, projects events |
| LLM workers | Model calls via Messenger consumers |
| Tool workers | Tool execution consumers |
| MCP / extension side work | As registered for the session |

The TUI talks to the controller through `AgentSessionClient` (`Runtime/Contract` + `Runtime/Protocol`). Process mode uses JSONL stdin/stdout (`JsonlProcessAgentSessionClient`); in-process mode calls AgentCore directly for tests/simple runs.

## Event flow

1. User submits a message → TUI writes a command to the client.
2. Controller admits work, appends canonical events to `events.jsonl`.
3. Workers execute LLM/tool jobs and emit results.
4. `RuntimeEventPoller` (TUI) reads projected events and updates the screen.

Canonical replay source is the session event log — not transient stream deltas. See [session-storage.md](session-storage.md).

## Supervision (`ConsumerSupervisor`)

Controller-owned messenger consumers (LLM/tool/…) are supervised with concrete invariants:

- Doctrine claim semantics: once a row is claimed (`delivered_at` set), age never restores eligibility. Abandoned claimed deliveries stay stuck until explicit `/repair` or restart-and-continue recovery.
- Consumers launch without `--keepalive`. SIGALRM lease refresh is not part of the runtime contract.
- Restart budget: max **3** restarts per consumer key within a **60s** window, initial restart delay **1s**; beyond budget the consumer is abandoned and the controller can surface a diagnostic.
- Shared consumer graceful shutdown grace defaults to **5s**; partial stdout line buffer max **65_536** bytes; stderr tail retained for crash diagnostics (**16_384** bytes).
- Consumer memory recycle threshold **256M** via Messenger worker options.

Other notes:

- `HATFIELD_BINARY_PATH` selects the executable used for subprocesses (PHAR/static/tests).
- Cancellation and shutdown are best-effort across workers; MCP disconnect is best-effort on worker stop ([mcp.md](mcp.md)).

## Boundaries

- Product TUI may depend on CodingAgent services directly; session/runtime protocol still uses Runtime Contract/Protocol. TUI must not depend on AgentCore internals.
- AgentCore must not depend on CodingAgent/TUI.
- Extension feature UX stays in extension packages; runtime ports stay generic.

## Related

- Process executable resolution: `src/CodingAgent/Runtime/Process/AGENTS.md`
- TUI: [tui-architecture.md](tui-architecture.md)
- Tool execution internals: [tool-execution.md](tool-execution.md)
- Distribution: [distribution.md](distribution.md)
