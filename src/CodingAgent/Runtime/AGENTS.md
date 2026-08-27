# Runtime model

- `AgentSessionClient` is the TUI/runtime boundary.
- `Runtime/Contract` and `Runtime/Protocol` define command/event DTOs.
- `Runtime/InProcess` calls AgentCore directly; `Runtime/Process` uses headless JSONL subprocess.
- `src/CodingAgent/CLI/AgentCommand.php` wires TUI via `Ineersa\Tui\Application\InteractiveMode`.
- Keep transient stream deltas separate from canonical replay. Canonical source: `.hatfield/sessions/<id>/events.jsonl` via `EventStoreInterface`.
