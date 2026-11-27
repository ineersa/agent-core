# TUI Testing

Prove TUI behavior at the **lowest correct layer** through Castor only. Authoritative standards: `tests/AGENTS.md` and `.agents/skills/testing/SKILL.md`.

## Layers

1. **Virtual / in-process** (`castor test`) — widgets, editor, local slash commands on `ScreenBuffer` / harnesses under `tests/Tui/`. Source PHP; no PHAR.
2. **Controller process** (`castor test:controller-replay`) — runtime protocol + event ordering without live LLM. Source `bin/console` with `APP_ENV=test`; no packaged artifact required for the behavioral path.
3. **Minimal tmux** (`castor test:tui`) — real TTY/process smoke for the `tui-e2e-replay` group. Behavioral journey cases still drive source `bin/console`. Separately, `TuiArtifactBootE2eTest` boots a packaged artifact.

Do not default every change to tmux. Service-only DTO tests or manual notes are not sole proof for TUI product behavior.

## PHAR and artifact boot

| Command | Behavioral TUI path | Packaged artifact |
|---|---|---|
| `castor test:tui` | Source `bin/console` for journey cases | Always runs `phar:ensure` and sets `HATFIELD_BINARY_PATH` + `HATFIELD_REQUIRE_ARTIFACT=1` so `TuiArtifactBootE2eTest` cannot soft-pass |
| `castor check` (TUI lane) | Same builder as `test:tui` | Same PHAR ensure as above |
| `castor test:tui-update` | Reruns `tui-e2e-replay` sequentially with `HATFIELD_UPDATE_SNAPSHOTS=1` | Does **not** call `phar:ensure`. `TuiArtifactBootE2eTest` fails without a PHAR or dist artifact and uses an existing one as-is, so ensure a fresh PHAR first |

Artifact boot is a packaging/boot contract, not proof of interactive behavior. Interactive journeys use source and isolated session evidence.

There are no checked-in TUI snapshot baselines, and no test currently reads
`HATFIELD_UPDATE_SNAPSHOTS`. ANSI snapshots saved by `TmuxHarness::saveAnsiSnapshot()`
are diagnostics under `var/reports/<run-id>/diagnostics/`; see
[temporary artifacts](test-artifacts.md).

## Commands

```bash
castor test
castor test:controller-replay
castor test:tui
castor test:tui --filter=TuiResumeSessionSwitchE2eTest   # focused, sequential PHPUnit
castor phar:ensure && castor test:tui-update   # if artifact boot must stay green
castor check             # full gate; TUI lane includes PHAR ensure
```

## Isolation

- Use `TestDirectoryIsolation::createProjectTempDir()`, which allocates owned directories under `var/tmp/<HATFIELD_QA_RUN_ID>/` — never real user `.hatfield/sessions/`.
- Wait on bounded harness predicates such as `waitForTuiReady()` and `waitForCaptureContains()`; never synchronize with fixed sleeps.
- Leaked workers are lifecycle bugs; diagnose with `castor clean:cleanup:workers:list`.

## Related

- Standards: `tests/AGENTS.md`, `.agents/skills/testing/SKILL.md`
- Provider HTTP cassettes: [llm-replay.md](llm-replay.md)
- Architecture: [TUI architecture](../architecture/tui.md)
