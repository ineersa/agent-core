---
builtin: true
description: Background process tracking, logs, stop behavior, and related settings.
---

# Background Processes

Long-running shell work can detach into tracked background processes so the conversation loop stays responsive.

There is **no** model-facing `bg_start` tool. Backgrounding is offered from **`bash`** when a
command exceeds `tools.bash.background_prompt_threshold_seconds` (default `15`). The **user**
chooses whether to move the command to the background; the model does not control that choice.
After backgrounding, inspect or stop processes with `bg_status`.

## Model / user tool: `bg_status`

| Action | Meaning |
|---|---|
| `list` | Show accepted background processes for the current session |
| `log` | Read recent log output for an accepted process (`pid` required) |
| `stop` | TERM the accepted process group, wait grace, then KILL if needed (`pid` required) |

## Lifecycle

1. `bash` starts each command with a private `background_process` supervision row and `.pid` / `.status` / `.log` sidecars so it can poll, stop, and read the exact foreground result.
2. After the threshold, the TUI may prompt the user to background it. On accept, Hatfield marks that existing row `backgrounded_at`; only then is it visible to `bg_status` and eligible for completion notification.
3. Finished private foreground rows remain available for one five-minute scheduler interval, then recurring maintenance removes their exact row-owned sidecars and row. Accepted background rows are excluded from this provisional sweep.
4. `bg_status stop` resolves only accepted jobs belonging to the current session, sends `SIGTERM` to the process group, waits `tools.background_process.stop_grace_seconds`, then `SIGKILL` if still alive.

Tracking is **session-scoped**. `bg_status` exposes only rows explicitly accepted as background work; private foreground supervision is never listed, tailed, or stopped through that tool. Durable records live in `.hatfield/state.sqlite`; filesystem sidecars (PID/status/log) live under the configured tool path.

## Settings

| Key | Role | Default |
|---|---|---|
| `tools.bash.background_prompt_threshold_seconds` | When bash may offer backgrounding | `15` |
| `tools.background_process.path` | Storage directory | `.hatfield/tmp/bg` |
| `tools.background_process.stop_grace_seconds` | TERM grace period | `5` |
| `tools.background_process.log_tail_chars` | Default log tail size | `5000` |

See [settings.md](settings.md).

## Safety

- Prefer stop via `bg_status` rather than guessing PIDs.
- Background workloads may outlive a single LLM turn; still stop them when finished.
- Never signal unrelated host processes; tracking is limited to Hatfield-managed records for the session.
