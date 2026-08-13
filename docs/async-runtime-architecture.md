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

## Supervision

- Controller may supervise worker processes and restart policies as implemented in CodingAgent runtime process helpers.
- `HATFIELD_BINARY_PATH` selects the executable used for subprocesses (PHAR/static/tests).
- Cancellation and shutdown are best-effort across workers; MCP disconnect is best-effort on worker stop ([mcp.md](mcp.md)).

## Boundaries

- Product TUI code depends on runtime contracts, not AgentCore internals.
- AgentCore must not depend on CodingAgent/TUI.
- Extension feature UX stays in extension packages; runtime ports stay generic.

## Related

- Process executable resolution: `src/CodingAgent/Runtime/Process/AGENTS.md`
- TUI: [tui-architecture.md](tui-architecture.md)
- Tool execution internals: [tool-execution.md](tool-execution.md)
- Distribution: [distribution.md](distribution.md)
