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
Branch: task/tools-08-background-process-manager
Worktree: /home/ineersa/projects/agent-core-worktrees/tools-08-background-process-manager
Fork run: le1l92h1nurr
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

## Task workflow update - 2026-05-30T19:33:24.572Z
- Moved CODE-REVIEW → IN-PROGRESS.
- Summary: Moving back to IN-PROGRESS to address PR #73 reviewer findings and update task branch from current main after merged logging/observability changes. Follow-up should fix process-group fallback and stale liveness cache, consider logging integration, then revalidate and push PR update.

## Task workflow update - 2026-05-30T19:33:50.896Z
- Recorded fork run: 6stp6t4dfbk5
- Summary: Launched follow-up fork 6stp6t4dfbk5 in `/home/ineersa/projects/agent-core-worktrees/tools-08-background-process-manager` to update branch from current main/logging changes, fix PR #73 reviewer blockers (unsafe setsid fallback PGID and stale isAlive proc cache), add appropriate lifecycle diagnostics under the new logging conventions if available, run Castor validation, and commit fixes.

## Task workflow update - 2026-05-30T19:40:10.604Z
- Recorded fork run: 6stp6t4dfbk5
- Validation: Fork reported `castor test --filter=BackgroundProcessManagerTest|BgStatusToolTest`: 48 tests, 116 assertions, 0 failures.; Fork reported full `castor test`: 1525 tests, 11448 assertions, 0 failures.; Fork reported `castor deptrac`: 0 violations.; Fork reported `castor phpstan`: 0 errors.; Fork reported `castor cs-fix` then `castor cs-check`: 1 file auto-fixed, then clean.; Fork did not run full `castor check`; reported blocker/prerequisite is tmux + llama.cpp on port 9052.
- Summary: Follow-up fork 6stp6t4dfbk5 completed at commit `b0ab7163` on branch `task/tools-08-background-process-manager` after merging current `origin/main` (including OBS-01 structured logging). Fixed PR #73 reviewer blockers: safe `setsid` fallback now avoids recording parent PHP worker PGID and degrades to single-PID signaling; removed stale `isAlive()` proc cache so TERM grace is respected. Also split `$recordId` into `$filePrefix`/`$dbId`, added `start()` shell-escaping warning, made `cleanupStale()` isolate/log delete failures and continue, added PSR lifecycle diagnostics using new logging conventions, fixed test helper naming, and added graceful term-only-with-natural-exit test. Parent verified worktree HEAD is `b0ab7163` and status output is clean.

## Task workflow update - 2026-05-30T19:40:37.754Z
- Moved IN-PROGRESS → CODE-REVIEW.
- Pushed task/tools-08-background-process-manager to origin.
- branch 'task/tools-08-background-process-manager' set up to track 'origin/task/tools-08-background-process-manager'.
- Skipped PR creation (pushOnly: true).
- Validation: Fork reported focused `castor test --filter=BackgroundProcessManagerTest|BgStatusToolTest`: 48 tests, 116 assertions, 0 failures.; Fork reported full `castor test`: 1525 tests, 11448 assertions, 0 failures.; Fork reported `castor deptrac`: 0 violations.; Fork reported `castor phpstan`: 0 errors.; Fork reported `castor cs-check`: clean after `castor cs-fix`.; Full `castor check` not run by fork; reported unavailable prerequisites/blocker: tmux + llama.cpp on port 9052.
- Summary: Review fixes complete at `b0ab7163`; moved back to CODE-REVIEW and pushed updates to existing PR #73.

## Task workflow update - 2026-05-30T19:45:25.009Z
- Validation: Reviewer verdict after `b0ab7163`: APPROVE WITH SUGGESTIONS.
- Summary: Reviewer subagent re-reviewed PR #73 after commit `b0ab7163` and returned APPROVE WITH SUGGESTIONS. Confirmed both prior critical blockers fixed: unsafe fallback PGID no longer recorded (`pgid = null` on fallback) and proc cache removed so liveness checks are fresh. No critical issues remain. Suggestions/non-blocking: fallback without `setsid` can still orphan child processes because wrapper ignores TERM and single-PID KILL only kills wrapper; consider documenting more or signaling children in fallback. Log messages should use stable dot-notation event names matching `event_type`; `shutdownCleanup()` should prefer PSR logger over `trigger_error`; logging path is not directly tested.

## Task workflow update - 2026-05-30T20:30:00.360Z
- Validation: Second reviewer verdict after `b0ab7163`: REQUEST CHANGES.
- Summary: Second independent reviewer subagent re-reviewed PR #73 at `b0ab7163` and returned REQUEST CHANGES. New blocking concern: shell wrapper uses `trap "" TERM`, which makes child processes inherit SIG_IGN for TERM, so `kill -TERM -PGID` does not gracefully terminate normal child workloads and stop effectively becomes KILL-only unless the child exits naturally during grace. Suggested wrapper pattern: run command as background child, trap TERM in wrapper to forward TERM to child, wait, write status, then exit. Additional issues: `shutdownCleanup()` still uses `trigger_error()` instead of PSR structured logger; add defense-in-depth guard for invalid PID <= 0 before kill; consider component naming and unused kill exit vars. This conflicts with prior reviewer approval that considered `trap "" TERM` acceptable in process-group mode; needs parent/user decision or follow-up fix.

## Task workflow update - 2026-05-30T20:37:50.530Z
- Moved CODE-REVIEW → IN-PROGRESS.
- Summary: Moving back to IN-PROGRESS to make background process stop semantics bulletproof after second reviewer found `trap "" TERM` makes child workloads inherit SIG_IGN and defeats graceful TERM. Follow-up should redesign the wrapper so TERM reaches the child, status sidecar is still written, remove unsafe fallback semantics, and address related logging/PID guard findings.

## Task workflow update - 2026-05-30T20:38:24.520Z
- Recorded fork run: le1l92h1nurr
- Summary: Launched fork le1l92h1nurr to make TOOLS-08 background-process stop semantics bulletproof. Instructions include: redesign wrapper to avoid `trap "" TERM` inherited SIG_IGN, make/keep `setsid` semantics safe (prefer requiring it over weak fallback), ensure TERM reaches actual workload while wrapper still writes status sidecar, KILL only after grace, avoid parent-worker kills/orphans, replace `trigger_error` with PSR structured logger, add PID/PGID guards, use dot-notation log messages, remove unused kill exit vars, add tests proving TERM reaches workload and KILL only follows ignored TERM, run Castor validation, and commit.

## Task workflow update - 2026-05-30T20:47:50.971Z
- Recorded fork run: le1l92h1nurr
- Validation: Fork reported `castor test --filter=BackgroundProcessManagerTest|BgStatusToolTest`: 50 tests, 123 assertions, 0 failures.; Fork reported full `castor test`: 1527 tests, 11455 assertions, 0 failures.; Fork reported `castor deptrac`: 0 violations.; Fork reported `castor phpstan`: 0 errors.; Fork reported `castor cs-check`: clean after `castor cs-fix` fixed 1 formatting issue.; Full `castor check` not run by fork; reported blocker/prerequisite is tmux + llama.cpp on port 9052.
- Summary: Fork le1l92h1nurr completed at commit `45313d63` on branch `task/tools-08-background-process-manager`. Made stop semantics bulletproof: removed unsafe fallback and now requires `setsid`; replaced `trap "" TERM` inherited-SIG_IGN wrapper with a child-forwarding wrapper where workload child keeps default TERM handling, wrapper traps TERM, forwards to child, waits, writes status sidecar, and exits; added PID guard before kill; switched `shutdownCleanup()` to PSR structured logger; changed lifecycle log messages to dot-notation event names; removed unused signal exit vars; added tests proving TERM reaches workload and KILL is only used for TERM-ignoring workloads. Parent verified worktree HEAD is `45313d63` and status output is clean.

## Task workflow update - 2026-05-30T20:47:59.434Z
- Moved IN-PROGRESS → CODE-REVIEW.
- Pushed task/tools-08-background-process-manager to origin.
- branch 'task/tools-08-background-process-manager' set up to track 'origin/task/tools-08-background-process-manager'.
- Skipped PR creation (pushOnly: true).
- Validation: Fork reported focused `castor test --filter=BackgroundProcessManagerTest|BgStatusToolTest`: 50 tests, 123 assertions, 0 failures.; Fork reported full `castor test`: 1527 tests, 11455 assertions, 0 failures.; Fork reported `castor deptrac`: 0 violations.; Fork reported `castor phpstan`: 0 errors.; Fork reported `castor cs-check`: clean after `castor cs-fix`.; Full `castor check` not run by fork; reported unavailable prerequisites/blocker: tmux + llama.cpp on port 9052.
- Summary: Bulletproof stop semantics fixes complete at `45313d63`; moved back to CODE-REVIEW and pushed updates to existing PR #73.

## Task workflow update - 2026-05-30T20:56:07.568Z
- Validation: Reviewer verdict after `45313d63`: APPROVE WITH SUGGESTIONS.
- Summary: Reviewer subagent re-reviewed PR #73 after commit `45313d63` and returned APPROVE WITH SUGGESTIONS. Confirmed critical inherited-SIG_IGN issue is resolved: new wrapper backgrounds workload with default TERM handling, forwards TERM, requires `setsid`, and no new process-safety blocker found. Suggestions: `stop()` currently overwrites status sidecar with `-1` even after wrapper wrote real exit code; `resolvePgid()` should retry briefly to avoid startup race; test script sentinel path should be shell-escaped; wrapper normal path should store RC before echo so `exit` reflects child RC; update stop docblock after required setsid; consider logging `refreshRecord()` DB exceptions.
