# TUI Testing

Prove TUI behavior at the **lowest correct layer** through Castor only. Authoritative standards: `tests/AGENTS.md` and `.agents/skills/testing/SKILL.md`.

## Layers

1. **Virtual / in-process** (`castor test`) — widgets, editor, local slash commands on `ScreenBuffer` / harnesses under `tests/Tui/`. Source PHP; no PHAR.
2. **Controller replay** (`castor test:controller-replay`) — runtime protocol + event ordering without live LLM. Source `bin/console` with `APP_ENV=test`; no packaged artifact required for the behavioral path.
3. **Minimal tmux replay** (`castor test:tui`) — real TTY/process smoke for the `tui-e2e-replay` group. Behavioral journey/snapshot cases still drive source `bin/console`. Separately, `TuiArtifactBootE2eTest` boots a packaged artifact.

Do not default every change to tmux. Service-only DTO tests or manual notes are not sole proof for TUI product behavior.

## PHAR and artifact boot

| Command | Behavioral TUI path | Packaged artifact |
|---|---|---|
| `castor test:tui` | Source `bin/console` for journey/snapshot cases | Always runs `phar:ensure` and sets `HATFIELD_BINARY_PATH` + `HATFIELD_REQUIRE_ARTIFACT=1` so `TuiArtifactBootE2eTest` cannot soft-pass |
| `castor check` (TUI lane) | Same builder as `test:tui` | Same PHAR ensure as above |
| `castor test:tui-update` | Snapshot refresh for `tui-e2e-replay` | Does **not** call `phar:ensure`. Ensure or build a PHAR first if artifact boot must pass |

Artifact boot is a packaging/boot contract, not proof of interactive behavior. Interactive journeys stay on source + replay fixtures.

## Commands

```bash
castor test
castor test:controller-replay
castor test:tui
castor phar:ensure && castor test:tui-update   # if artifact boot must stay green
castor check             # full gate; TUI lane includes PHAR ensure
```

## Isolation

- Use `TestDirectoryIsolation` project temp trees — never real user `.hatfield/sessions/`.
- Prefer early-exit wait helpers over fixed long sleeps.
- Leaked workers are lifecycle bugs; diagnose with `castor clean:cleanup:workers:list`.

## Related

- Standards: `tests/AGENTS.md`, `.agents/skills/testing/SKILL.md`
- LLM fixtures: [llm-replay.md](llm-replay.md)
- Architecture: [tui-architecture.md](tui-architecture.md)
