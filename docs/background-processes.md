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
| `list` | Show tracked processes for the session |
| `log` | Read recent log output for a process (`pid` required) |
| `stop` | TERM the process group, wait grace, then KILL if needed (`pid` required) |

## Lifecycle

1. `bash` runs a command; after the threshold, the TUI may prompt the user to background it.
2. On accept, Hatfield persists a durable `background_process` row in `.hatfield/state.sqlite` and returns a notice with PID + log path.
3. PID / status / log sidecars are written under `tools.background_process.path` (default `.hatfield/tmp/bg`): `.pid`, `.status`, and `.log` companions for the live process.
4. `bg_status stop` resolves the process group, sends `SIGTERM`, waits `tools.background_process.stop_grace_seconds`, then `SIGKILL` if still alive.

Tracking is **session-scoped**. Durable records live in `.hatfield/state.sqlite`; filesystem sidecars (PID/status/log) live under the configured tool path.

## Settings

| Key | Role | Default |
|---|---|---|
| `tools.bash.background_prompt_threshold_seconds` | When bash may offer backgrounding | `15` |
| `tools.background_process.path` | Storage directory | `.hatfield/tmp/bg` |
| `tools.background_process.retention` | Stale log/record retention seconds | `86400` |
| `tools.background_process.stop_grace_seconds` | TERM grace period | `5` |
| `tools.background_process.log_tail_chars` | Default log tail size | `5000` |

See [settings.md](settings.md).

## Safety

- Prefer stop via `bg_status` rather than guessing PIDs.
- Background workloads may outlive a single LLM turn; still stop them when finished.
- Never signal unrelated host processes; tracking is limited to Hatfield-managed records for the session.
