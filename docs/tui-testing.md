# TUI Testing with tmux

This document describes how to launch, inspect, snapshot, and test the
Hatfield TUI using tmux and Castor commands.

## Quick start

```bash
castor run:agent           # Launch interactive TUI in tmux
castor run:agent-test      # Deterministic test session with snapshot
```

## `castor run:agent`

Launches the agent TUI in tmux. The TUI blocks until the user exits
via Ctrl+D or double Ctrl+C.

**Inside tmux:** creates a new window named `hatfield-agent` in the
current session. This keeps your existing session layout intact.

**Outside tmux:** creates a new session named `hatfield-agent` (or
attaches to it if it already exists). This task attaches tmux directly
to the caller's TTY rather than through Castor's process runner so raw
keys and terminal dimensions pass through correctly.

The command run in the tmux pane/window is simply:

```bash
php bin/console agent
```

On startup the footer should render live status information, for example:

```text
◆ deepseek-v4-pro  |  0/0 $0.00 0% 0/1000.0k  |  ⏱ 0s  |  ⌂ agent-core  |  ⎇ main
```

The elapsed-time segment is expected to update while the TUI is idle. If the
window opens and immediately exits, run `php bin/console agent` directly from
the project root to see the fatal error before tmux closes the pane.

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

These ARE included in `castor check` (the gate fails if tmux is not installed).
Run `castor test:tui` explicitly when testing TUI rendering during development.
Any intentional footer/header/layout change should be followed by
`castor test:tui-update` and a review of the golden snapshot diff.

Pure unit/integration tests (`castor test`) remain source-based.

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

### Adding new e2e tests

1. Place the test class in `tests/Tui/E2E/`.
2. Add `#[Group('tui-e2e-replay')]` to the class.
3. Use `TmuxHarness` to start sessions, send keys, capture panes.
4. Assert against committed golden snapshots or use `contains`
   assertions for content presence.
5. Run `castor test:tui` to verify.

### Extending e2e tests

The TUI event loop is fully interactive now. Future tests can:

- Simulate multi-turn conversations (send prompt → wait for response)
- Test keybindings (Ctrl+C single/double, tool expand/collapse)
- Test resize behavior and responsive layout
- Test overlays (help, session list, tool details)
- Capture ANSI snapshots for theme validation

The `castor run:agent-test` task remains the manual/LLM inspection
harness. The `TmuxHarness` + `test:tui` tasks are the automated counterpart.

## Troubleshooting

**"tmux is not installed"**
Install tmux via your package manager:
```bash
sudo apt install tmux       # Debian/Ubuntu
brew install tmux           # macOS
```

**Session already exists but pane is dead**
```bash
tmux kill-session -t hatfield-agent
castor run:agent

# or for the deterministic test session
tmux kill-session -t hatfield-agent-test
castor run:agent-test
```

**`castor run:agent` opens a window but the agent does not stay running**
Run the console command directly to surface the PHP error:

```bash
php bin/console agent
```

After fixing the error, re-run `castor run:agent` and confirm tmux shows a live
`php` process in the `hatfield-agent` window/pane.

**Keys show up as escape sequences / Ctrl keys do not work**
This usually means tmux was attached through a non-TTY wrapper or you are
attached to a stale session. Kill and recreate the session:

```bash
tmux kill-session -t hatfield-agent
castor run:agent
```

**Snapshot is empty or truncated**
Wait a bit longer before capturing. The agent may still be starting.
Increase the sleep in `run_agent_test()` if needed, or attach and
capture manually.

**ANSI colors look garbled in the snapshot**
Use `capture-pane -p -e` for ANSI-preserving snapshots. These contain
escape sequences that display correctly in a terminal but not in a
plain text editor unless you pipe through `less -R` or similar.
