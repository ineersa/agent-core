# TUI Testing

Prove TUI behavior at the **lowest correct layer** through Castor only.

## Layers

1. **Virtual / in-process** (`castor test`) — widgets, editor, local slash commands on `ScreenBuffer` / harnesses under `tests/Tui/`.
2. **Controller replay** (`castor test:controller-replay`) — runtime protocol + event ordering without live LLM.
3. **Minimal tmux** (`castor test:tui`) — real TTY/process smoke only when required.

Do not default every change to tmux. Service-only DTO tests or manual notes are not sole proof for TUI product behavior.

## Commands

```bash
castor test
castor test:controller-replay
castor test:tui
castor test:tui-update   # refresh snapshots intentionally
castor check             # full gate including TUI replay lane
```

## Isolation

- Use `TestDirectoryIsolation` project temp trees — never real user `.hatfield/sessions/`.
- Prefer early-exit wait helpers over fixed long sleeps.
- Leaked workers are lifecycle bugs; diagnose with `castor clean:cleanup:workers:list`.

## Related

- Standards: `tests/AGENTS.md`, `.agents/skills/testing/SKILL.md`
- LLM fixtures: [llm-replay.md](llm-replay.md)
