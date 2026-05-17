# TOOLS-08 Implement background process manager and bg_status tool

## Goal
Implement background process state management and the `bg_status` companion tool.

Plan source: `.pi/plans/toolbox-design-plan.md`.

Dependencies:
- Depends on TOOLS-00 (`CancellableProcessRunner` process termination semantics/helpers).

Scope:
- Create `src/CodingAgent/Tool/BackgroundProcessManager.php`.
- Create/complete `src/CodingAgent/Tool/BgStatusTool.php`.
- `BackgroundProcessManager` tracks backgrounded processes: pid, command, log file, startedAt, finished, exitCode, stoppedByUser.
- Logs live under `.hatfield/tmp/bg/<session-prefix>-<pid>.log` or equivalent safe unique name.
- Expose manager operations: register/start tracking, list, read log tail, stop PID with SIGTERM, cleanup stale log files older than 24h, shutdown cleanup for running processes.
- Implement `bg_status` tool schema: `__invoke(string $action, ?int $pid = null)` where action is `list`, `log`, or `stop`.
- `list`: show PID, status, log path, and command.
- `log`: return tail of log file, capped to a reasonable size (around 5k chars) and include truncation marker.
- `stop`: mark stoppedByUser and terminate the process using the shared TERM -> grace -> KILL semantics/helpers from TOOLS-00 where practical.
- Add focused tests. Use short-lived `sleep`/`printf` processes where practical; isolate temp directories.

Out of scope:
- Do not implement the bash tool's foreground execution or 30s prompt here.
- Do not add model-controlled `run_in_background`.

## Acceptance criteria
- `bg_status` tool is discoverable through Symfony AI toolbox metadata.
- Manager can list, read log, and stop registered background processes.
- Stop/shutdown cleanup does not leave child processes running where process-group termination is supported.
- Log files are stored under `.hatfield/tmp/bg/` and parent directories are created as needed.
- Stale log cleanup removes files older than 24 hours.
- Shutdown cleanup terminates tracked running processes.
- Tests cover list/log/stop and stale cleanup without leaking processes.
- Focused tests pass with Castor/PHPUnit.

## Workflow metadata
Status: TODO
Branch:
Worktree:
Fork run:
PR URL:
PR Status:
Started:
Completed:

## Work log
- Created: 2026-05-17T04:42:49.755Z
