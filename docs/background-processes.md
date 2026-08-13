---
builtin: true
description: Background process tracking, logs, stop behavior, and related settings.
---

# Background Processes

Long-running shell work can detach into tracked background processes so the conversation loop stays responsive.

## Model / user tool: `bg_status`

| Action | Meaning |
|---|---|
| `list` | Show tracked processes for the session |
| `log` | Read recent log output for a process |
| `stop` | TERM the process group, wait grace, then KILL if needed |

## Lifecycle

1. Start records a running process (session-scoped tracking DB under `.hatfield/tmp/bg/`).
2. Stdout/stderr append to a log file; a status sidecar records exit codes when available.
3. Stop resolves the process group, sends `SIGTERM`, waits `tools.background_process.stop_grace_seconds`, then `SIGKILL` if still alive.

Tracking is **session-scoped**. Logs and DB files live under `tools.background_process.path` (default `.hatfield/tmp/bg`).

## Settings

| Key | Role |
|---|---|
| `tools.background_process.path` | Storage directory |
| `tools.background_process.retention` | Stale log/record retention seconds |
| `tools.background_process.stop_grace_seconds` | TERM grace period |
| `tools.background_process.log_tail_chars` | Default log tail size |
| `tools.bash.*` | Timeouts / background threshold for bash integration |

See [settings.md](settings.md).

## Safety

- Prefer stop via `bg_status` rather than guessing PIDs.
- Background workloads may outlive a single LLM turn; still stop them when finished.
- Never signal unrelated host processes; tracking is limited to Hatfield-managed records for the session.
