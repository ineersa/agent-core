# TOOLS-08 Implement background process manager and bg_status tool

## Goal
Implement background process state management and the `bg_status` companion tool.

Plan source: `.pi/plans/toolbox-design-plan.md`.

Dependencies:
- Depends on TOOLS-R02 (Hatfield tool definition convention) and TOOLS-R03 (registry-backed Toolbox, settings, and allowlist wiring).
- Depends on TOOLS-00 (`ToolProcessRegistry` and `ToolProcessTerminator` process tracking/termination helpers).

Scope:
- Create `src/CodingAgent/Tool/BackgroundProcessManager.php` using the shared TOOLS-00 process registry/terminator primitives where practical.
- Create/complete `src/CodingAgent/Tool/BgStatusTool.php`.
- Provide a Hatfield tool definition/provider for `bg_status` instead of relying on `#[AsTool]` metadata.
- Register `bg_status` as a permanent tool through the TOOLS-R02 built-in tool registrar/`ToolRegistryInterface`, including provider description, explicit JSON schema, prompt line, and concise guidelines. Execution flows through the TOOLS-R03 registry-backed Toolbox.
- `BackgroundProcessManager` tracks backgrounded processes: pid, process group id when available, command, log file, startedAt, finished, exitCode, stoppedByUser.
- Logs live under `.hatfield/tmp/bg/<session-prefix>-<pid>.log` or equivalent safe unique name.
- Read cleanup retention and process termination grace from Hatfield tool settings introduced by TOOLS-R04.
- Expose manager operations: register/start tracking, list, read log tail, stop PID with SIGTERM, cleanup stale log files older than 24h, shutdown cleanup for running processes.
- Implement `bg_status` tool schema: `__invoke(string $action, ?int $pid = null)` where action is `list`, `log`, or `stop`.
- `list`: show PID, status, log path, and command.
- `log`: return tail of log file, capped to a reasonable size (around 5k chars) and include truncation marker.
- `stop`: mark stoppedByUser and terminate the process using `ToolProcessTerminator` TERM -> grace -> KILL semantics from TOOLS-00.
- Add focused tests. Use short-lived `sleep`/`printf` processes where practical; isolate temp directories.

Out of scope:
- Do not implement the bash tool's foreground execution or 30s prompt here.
- Do not add model-controlled `run_in_background`.

## Acceptance criteria
- `bg_status` tool is discoverable through registry-backed Symfony Toolbox metadata and present in `ToolRegistryInterface` permanent metadata.
- Manager can list, read log, and stop registered background processes.
- Stop/shutdown cleanup does not leave child processes running where TOOLS-00 process-group termination is supported.
- Log files are stored under `.hatfield/tmp/bg/` and parent directories are created as needed.
- Stale log cleanup removes files older than 24 hours.
- Shutdown cleanup terminates tracked running processes.
- Tests cover list/log/stop and stale cleanup without leaking processes.
- Focused tests pass with Castor/PHPUnit.

## Workflow metadata
Status: CODE-REVIEW
Branch:
Worktree:
Fork run: n432vg5e53fc
PR URL: https://github.com/ineersa/agent-core/pull/73
PR Status: open
Started:
Completed:

## Work log
- Created: 2026-05-17T04:42:49.755Z

## Task workflow update - 2026-05-30T19:12:58.223Z
- Recorded fork run: n432vg5e53fc
- Validation: Fork reported `castor test`: 1495 tests, 11341 assertions, 0 failures.; Fork reported `castor deptrac`: 0 violations.; Fork reported `castor phpstan` on changed files: 0 errors; full phpstan still has 12 pre-existing `.castor/tasks.php` errors unrelated to TOOLS-08.; Fork reported `castor cs-check`: clean.; Focused tests: 23 `BackgroundProcessManagerTest` tests and 24 `BgStatusToolTest` tests pass.
- Summary: Fork n432vg5e53fc completed and committed TOOLS-08 implementation at `9f819724` on branch `task/tools-08-background-process-manager`. Implemented typed `BackgroundProcessConfig`, DBAL-backed `BackgroundProcessManager`, `BgStatusTool` list/log/stop provider, settings/docs wiring, and focused manager/tool tests. Design uses DBAL durable records, per-process log/status sidecar files, `setsid` process-group startup when available with single-PID fallback, TERM→grace→KILL stop semantics, and no automatic normal shutdown cleanup so background jobs survive across tool calls. Parent verified worktree HEAD is `9f819724` and status output is clean.

## Task workflow update - 2026-05-30T19:14:15.287Z
- Moved TODO → CODE-REVIEW.
- Validation: Fork reported `castor test`: 1495 tests, 11341 assertions, 0 failures.; Fork reported `castor deptrac`: 0 violations.; Fork reported `castor phpstan` on changed files: 0 errors; full phpstan has 12 pre-existing `.castor/tasks.php` errors unrelated to TOOLS-08.; Fork reported `castor cs-check`: clean.
- Summary: Implementation complete at worktree commit `9f819724`; moving to code review for PR and reviewer verification.

## Task workflow update - 2026-05-30T19:14:43.744Z
- Updated PR URL: https://github.com/ineersa/agent-core/pull/73
- Updated PR Status: open
- Summary: Pushed branch `task/tools-08-background-process-manager` and created PR #73: https://github.com/ineersa/agent-core/pull/73. Note: because the task metadata was still in TODO after the implementation fork, moving TODO→CODE-REVIEW did not auto-create the PR, so the branch push and PR creation were performed explicitly.

## Task workflow update - 2026-05-30T19:21:23.879Z
- Validation: Reviewer verdict: REQUEST CHANGES for PR #73.
- Summary: Reviewer subagent completed review of PR #73 / commit `9f819724` and returned REQUEST CHANGES. Blocking issues: (1) `BackgroundProcessManager` fallback path without `setsid` records parent PHP worker PGID, so `stop()` may signal the whole worker process group; fix by not recording PGID on fallback or failing if setsid unavailable. (2) `isAlive()` caches `/proc/<pid>` results and never invalidates, so after TERM `stop()` still sees stale alive=true and always sends KILL, making grace semantics ineffective; remove proc cache or invalidate after signals and update tests. Additional non-blocking items: split `$recordId` file-prefix vs DB id naming, cleanupStale should continue after single delete failure, clarify shell-command escaping contract for public `start()`, test helper timing naming, consider ToolRuntime cancellation for bg_status stop sleep.
