---
description: Background process tracking, logs, stop behavior, and related settings.
---

# Background Processes

Background processes let the agent run long-running shell commands without
blocking the conversation loop.

## Architecture

### BackgroundProcessManager

SQLite-backed process lifecycle manager (`src/CodingAgent/Tool/BackgroundProcessManager.php`).

Each background process gets:

- A unique PID tracked in a per-session SQLite database (`.hatfield/tmp/bg/<session_id>.db`)
- A log file under `.hatfield/tmp/bg/` capturing stdout and stderr
- A TERM-forwarding wrapper script that:
  1. Traps SIGTERM and forwards it to the child workload
  2. Writes a status sidecar file (`<log>.status`) with the workload's exit code on exit
  3. Ensures clean process tree teardown

### Process lifecycle

```
start(command, sessionId)
  → validate setsid available
  → create DB record (status: running)
  → write bash wrapper script to temp file
  → launch via Symfony Process (setsid, detached)
  → write .pid file

stop(pid, sessionId)
  → resolve PGID from PID
  → send SIGTERM to process group (-pgid)
  → wait grace_seconds for exit
  → if still running: SIGKILL
  → write status sidecar if missing
  → update DB record (status: stopped/finished)

shutdownCleanup(sessionId?)
  → find all running processes (optionally filtered by session)
  → stop each one
  → delete stale log files older than 24h
```

### Session ownership

Every process is owned by the session that started it (`session_id` column).
The `bg_status` tool scopes list/log/stop operations to the current session
using `ToolContext::runId()`. Cross-session visibility is blocked.

### Cleanup on exit

Two-layer shutdown strategy:

| Layer | When | Scope |
|---|---|---|
| `HeadlessController::shutdown()` | SIGTERM/SIGINT signal handler | Session-scoped cleanup |
| `register_shutdown_function` (via `registerShutdownHandler()`) | Normal exit, `exit()`, fatal error | All sessions in this PHP process |
| None | SIGKILL, OOM, segfault | Processes survive for resume |

On crash (SIGKILL/OOM/segfault), background processes survive because:

- They run in their own session (`setsid`)
- Their log files persist on disk
- The agent can inspect/stop them on resume via `bg_status`

### Configuration

Settings in `.hatfield/settings.yaml` under `background_process`:

```yaml
background_process:
  storage_dir: ".hatfield/tmp/bg"     # Log and DB storage
  stop_grace_seconds: 5               # Grace period before SIGKILL
  log_tail_chars: 5000                # Max chars returned by log action
```

## bg_status tool

The `bg_status` tool provides three actions for the LLM.

### list

Lists background processes for the current session.

```json
{"action": "list"}
```

Returns a table of running processes with PID, command, status, start time,
and log path.

### log

Tails the log of a background process.

```json
{"action": "log", "pid": 12345}
```

Returns the last N characters of the process log (configurable via
`log_tail_chars`). Includes truncation indicator if the log exceeds the limit.

### stop

Gracefully stops a background process.

```json
{"action": "stop", "pid": 12345}
```

Sends SIGTERM to the process group, waits the configured grace period, then
SIGKILL if still running. Returns the final exit status.

## Stale process handling

- `cleanupStale()` removes DB records for processes finished more than 24
  hours ago
- `cleanupOrphanedPidFiles()` removes `.pid` files with no matching running
  process
- Stale cleanup runs automatically during `start()` and `shutdownCleanup()`
