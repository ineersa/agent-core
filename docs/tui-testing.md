# TUI Testing with tmux

This document describes how to launch, inspect, snapshot, and test the
Hatfield TUI using tmux and Castor commands.

## Quick start

```bash
castor run:agent           # Launch interactive TUI in the current terminal
castor run:agent-test      # Manual tmux test helper (deterministic snapshot)
```


## Bubblewrap auto-wrap (`run:agent`, `run:agent-capture`)

When `~/bin/pi-bwrap` exists and is executable, `castor run:agent` and `run:agent-capture` re-exec Castor under that wrapper, then launch `php bin/console agent` in the **current terminal** inside the sandbox. Datadog flags (`HATFIELD_DATADOG`) are unchanged.

`run:agent-test` does **not** use auto-wrap: the host tmux server starts the pane command outside Bubblewrap. Use `run:agent` for local read-only-home sandboxing.

| Variable | Effect |
| --- | --- |
| `HATFIELD_BWRAP=0` | Skip Bubblewrap (host launch, troubleshooting) |
| `HATFIELD_PI_BWRAP=/path/to/script` | Override wrapper path (default `~/bin/pi-bwrap`) |
| `HATFIELD_INSIDE_PI_BWRAP=1` | Set automatically after re-exec; prevents recursive wrapping |
| `HATFIELD_CASTOR_EXECUTABLE=/path/to/castor` | Override Castor CLI used for the Bubblewrap re-exec (default: auto-detect from current invocation `argv[0]`, then `~/.local/bin/castor`, then `PATH`) |

`castor test:tui` / `TmuxHarness` do **not** use Bubblewrap (PHPUnit runs on the host). Bubblewrap is optional local sandboxing only; Hatfield behavior is the same with or without it. The QA gate still requires host tmux.

Manual checks on stock `~/bin/pi-bwrap`: writes under unshared `$HOME` paths should fail; writes under the project tree (via `~/projects` bind) should succeed. After `castor run:agent`, asking the agent to write `~/test.md` should fail when bwrap is active.

## `castor run:agent`

Launches the agent TUI in the **current terminal**. The TUI blocks until you exit
via Ctrl+D or double Ctrl+C. Castor uses `exec bash -lc 'cd <root> && exec … php bin/console agent'`
so raw keys and terminal dimensions pass through correctly.

When `~/bin/pi-bwrap` is available, Castor re-execs under it first; the agent process
then runs inside the sandbox in this same terminal.

Equivalent console command from the project root:

```bash
php bin/console agent
```

On startup the footer should render live status information, for example:

```text
◆ deepseek-v4-pro  |  0/0 $0.00 0% 0/1000.0k  |  ⏱ 0s  |  ⌂ agent-core  |  ⎇ main
```

The elapsed-time segment is expected to update while the TUI is idle. If the
TUI exits immediately, run `php bin/console agent` directly from the project
root to see the fatal error.

### TUI keybindings

| Key | Action |
|-----|--------|
| Enter | Submit the current prompt to the agent |
| Ctrl+J | Insert newline (portable, works in all terminals) |
| Shift+Enter | Insert newline (may not work in all terminals) |
| Ctrl+D | Exit the TUI cleanly |
| Ctrl+C | Cancel / clear editor (press twice within 1.5s to exit) |
| Escape | Cancel / clear editor |
| Arrow keys | Navigate editor cursor |
| Ctrl+A / Home | Move cursor to line start |
| Ctrl+E / End | Move cursor to line end |
| Ctrl+W | Delete word backward |
| Ctrl+K | Delete to end of line |
| Ctrl+U | Delete to start of line |
| Tab | Accept/trigger completion |
| Up on empty editor | Recall previous prompt |
| Ctrl+P | Cycle model |
| Ctrl+O | Toggle transcript preview expansion (session-only) |
| Shift+Tab | Cycle reasoning level |

Type `/hotkeys` while the TUI is running to see the live hotkey catalog.
The registry is display-only; input execution stays with the underlying
Symfony EditorWidget and listener pipeline. There is no user-configurable
YAML keybinding loader (simplified from the original scope).

## `castor run:agent-test`

Creates a deterministic, reproducible tmux session for snapshot testing.

Behavior:
- Session name: `hatfield-agent-test`
- Fixed dimensions: 120×40 columns/rows (forced with `resize-window`
  after session creation for tmux servers that ignore `new-session -x/-y`)
- Runs the agent with `--prompt='hello from tui test'`
- The TUI event loop blocks indefinitely — the session stays alive
  until the user exits (Ctrl+D or double Ctrl+C)
- Captures a plain-text snapshot to `.hatfield/tmp/tui/latest.txt`
- Writes metadata (pane id, dimensions, timestamp) to
  `.hatfield/tmp/tui/agent-test.env`
- Re-running the command tears down and recreates the session from
  scratch

After running, you can:

```bash
# Inspect manually
tmux attach-session -t hatfield-agent-test

# Capture a plain-text snapshot
tmux capture-pane -p -t hatfield-agent-test

# Capture with ANSI escape codes preserved
tmux capture-pane -p -e -t hatfield-agent-test

# Send keys to the TUI
tmux send-keys -t hatfield-agent-test Enter
tmux send-keys -t hatfield-agent-test C-c

# Tear down
tmux kill-session -t hatfield-agent-test
```

## Snapshot artifacts

| File | Description |
|------|-------------|
| `.hatfield/tmp/tui/latest.txt` | Most recent plain-text snapshot |
| `.hatfield/tmp/tui/agent-test.env` | JSON metadata (pane id, dimensions, timestamp) |


These live in `.hatfield/tmp/` which is gitignored via
`.hatfield/.gitignore`. Snapshots are transient by design — use the
save-snapshot keybinding below to persist named snapshots.

Footer rendering is colorized per segment. Plain-text snapshots are best for
layout and content checks; ANSI snapshots (`capture-pane -p -e`) are useful
when checking theme colors, truncation, or separator styling. The footer uses
Symfony TUI ANSI utilities for width-aware truncation, so visible text should
truncate with `...` without cutting escape sequences.

## Manual snapshot commands

These work from any terminal, inside or outside tmux:

```bash
# Plain-text snapshot of the test session
tmux capture-pane -p -t hatfield-agent-test

# ANSI snapshot (keeps colors)
tmux capture-pane -p -e -t hatfield-agent-test

# Save to a file
tmux capture-pane -p -t hatfield-agent-test > snapshot.txt

# Save with timestamp
tmux capture-pane -p -t hatfield-agent-test > ".hatfield/tmp/tui/snap-$(date +%s).txt"
```

## Recommended tmux.conf keybinding

Add this to `~/.tmux.conf` for one-key snapshots:

```
# Bind Prefix+s to save a snapshot of the current pane
# The snapshot lands in .hatfield/tmp/tui/snapshots/ relative to
# the pane's working directory.
bind s run-shell 'dir="#{pane_current_path}/.hatfield/tmp/tui/snapshots"; mkdir -p "$dir"; tmux capture-pane -p -t "#{pane_id}" > "$dir/snap-$(date +%Y%m%d-%H%M%S).txt"'
```

Usage:
1. Press `Prefix + s` in the agent tmux session.
2. The snapshot is saved to
   `<project>/.hatfield/tmp/tui/snapshots/snap-YYYYMMDD-HHMMSS.txt`.

## Automated testing / golden snapshots

### PHPUnit tmux e2e tests

The project includes a reusable tmux test harness under
`tests/Tui/E2E/` and a startup snapshot test that validates the
TUI layout against committed golden fixtures.

```bash
# Run TUI E2E journey tests (tmux required — fails if not installed)
castor test:tui

# Update golden snapshots after intentional rendering changes
castor test:tui-update
```

These tests run against the source tree (`bin/console`), not the built PHAR.
The replay-backed TUI tests use `APP_ENV=test` so `config/services_test.yaml`
wires `ControllerReplayHttpClientFactory` for deterministic model responses.

Pure unit/integration tests (`castor test`) remain source-based.

### Test pyramid (preferred over tmux-only)

Automated TUI proof should use the **lowest correct layer**:

1. **Virtual / in-process** — `castor test`, `VirtualTuiHarness` + `ScreenBuffer` (`tests/Tui/Screen/`): layout, editor input, local slash commands, command routing. No tmux required.
2. **Controller replay** — `castor test:controller-replay`: runtime JSONL and session/event contracts.
3. **tmux integration** — `castor test:tui`: detached session, pty, process boot, replay-backed steps that still need a real terminal. `TuiJourneyE2eTest` is a **smoke** journey, not the default pattern for every new feature.

Do not add Journey phases for behavior already covered by virtual tests (see `TuiJourneyE2eTest` docblock for moved proofs).

These ARE included in `castor check` (the gate fails if tmux is not installed).
Run `castor test:tui` explicitly when testing TUI rendering during development.
Any intentional footer/header/layout change should be followed by
`castor test:tui-update` and a review of the golden snapshot diff.

### How it works

1. `TmuxHarness` creates a detached tmux session at 120×40.
2. The agent starts with a known `--prompt`.
3. The harness polls `capture-pane` until the header renders.
4. The plain-text snapshot is normalised (UUIDs → `<run-id>`, paths → `<root>`).
5. The normalised snapshot is compared against a committed golden fixture
   in `tests/Tui/Snapshots/`.

Send-keys and interactive testing are available via the harness.
The TUI now uses a real event loop (`Symfony\Component\Tui\Tui::run()`)
powered by Revolt, so tests can send keystrokes and assert responses.

```php
// Example interactive e2e test pattern
$pane = $tmux->startDetached('php bin/console agent', prefix: 'hatfield-interact');
$tmux->waitForCaptureContains($pane, '█'); // Hatfield logo rendered
$tmux->sendLiteral($pane, 'hello agent');
$tmux->sendKey($pane, 'Enter');
$tmux->waitForCaptureContains($pane, 'hello agent'); // user message appears
$tmux->sendKey($pane, 'C-d'); // clean exit
```

### Golden snapshot fixtures

| Fixture | Description |
|---------|-------------|
| `tests/Tui/Snapshots/startup-120x40.txt` | Normalised plain-text snapshot of the agent startup at 120×40 |

### Updating snapshots

After intentional layout or rendering changes:

```bash
castor test:tui-update
```

This sets `HATFIELD_UPDATE_SNAPSHOTS=1` after running the test, which
overwrites the golden fixture with the current output. Review the diff
(`git diff tests/Tui/Snapshots/`) before committing.

For footer changes, verify the golden snapshot no longer expects removed legacy
segments such as `◆ hatfield` or `Ctrl+D`, and that it includes the current
model/token/elapsed/cwd/branch status line where applicable.

### Adding new TUI tests

1. Choose the layer: virtual (`tests/Tui/Screen/`, `castor test`) unless you need tmux/pty/process boot.
2. For tmux tests: place class in `tests/Tui/E2E/`, add `#[Group('tui-e2e-replay')]`, use `TmuxHarness`, isolated `var/tmp/test-{uuid}`, replay fixtures when model output is needed.
3. Assert on `ScreenBuffer` plain text (virtual) or `capture-pane` / golden snapshots (tmux).
4. Run `castor test` and/or `castor test:tui` as appropriate — not every TUI feature requires `test:tui`.

### Extending e2e tests

The TUI event loop is fully interactive now. Future tests can:

- Simulate multi-turn conversations (send prompt → wait for response)
- Test keybindings (Ctrl+C single/double, tool expand/collapse)
- Test resize behavior and responsive layout
- Test overlays (help, session list, tool details)
- Capture ANSI snapshots for theme validation

The `castor run:agent-test` task remains the manual/LLM inspection
harness. Virtual tests (`castor test`) plus `TmuxHarness` + `test:tui` cover automated proof at the appropriate layers.

## Troubleshooting

**"tmux is not installed"**
Install tmux via your package manager:
```bash
sudo apt install tmux       # Debian/Ubuntu
brew install tmux           # macOS
```

**`castor run:agent-test` session is dead or stale**
```bash
tmux kill-session -t hatfield-agent-test
castor run:agent-test
```

**`castor run:agent` exits immediately**
Run the console command directly to surface the PHP error:

```bash
php bin/console agent
```

**Keys show up as escape sequences / Ctrl keys do not work (tmux test helper only)**
For `run:agent-test`, kill and recreate the tmux session:

```bash
tmux kill-session -t hatfield-agent-test
castor run:agent-test
```

For `run:agent` in the current terminal, ensure you are on a real TTY (not a pipe)
and that your terminal emulator passes through Ctrl sequences.

**Snapshot is empty or truncated**
Wait a bit longer before capturing. The agent may still be starting.
Increase the sleep in `run_agent_test()` if needed, or attach and
capture manually.

**ANSI colors look garbled in the snapshot**
Use `capture-pane -p -e` for ANSI-preserving snapshots. These contain
escape sequences that display correctly in a terminal but not in a
plain text editor unless you pipe through `less -R` or similar.
