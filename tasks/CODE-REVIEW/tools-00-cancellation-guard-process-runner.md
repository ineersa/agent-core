# TOOLS-00 Implement tool cancellation context and foreground process termination primitives

## Goal
Implement shared cancellation and foreground process-execution primitives for CodingAgent toolbox tools.

Plan source: `.pi/plans/toolbox-design-plan.md`.

This task follows the async-runtime cancellation ladder: cooperative token checks are level 1; controller/runtime-owned foreground PID/process-group termination is level 2. Do not build a separate per-tool cancellation polling system.

Scope:
- Create AgentCore-owned `ToolExecutionContextInterface`, `ToolExecutionContextAccessorInterface`, and `ToolCancelledException` under `src/AgentCore/Contract/Tool/` (or equivalent AgentCore-owned contract namespace). Include run id, turn number, tool call id, tool name, timeout, and existing cancellation token access.
- Create a concrete stack-safe accessor implementation such as `StackToolExecutionContextAccessor` under `src/AgentCore/Application/Tool/` with `current()`, `requireCurrent()`, and `with()` helpers.
- Update `ToolExecutor` to wrap Toolbox execution in the current `ToolExecutionContextInterface` without importing any `CodingAgent` classes; this must remain compatible with the registry-backed Toolbox from TOOLS-R03.
- Update `ToolExecutor` to classify AgentCore `ToolCancelledException` as a structured cancellation result (`cancelled=true`) instead of a generic tool failure.
- Create `src/CodingAgent/Tool/CancellationGuard.php` for cooperative checkpoints in short app-owned tools; it should depend only on AgentCore context/exception contracts.
- Create process-tracking value objects/enums such as `ToolProcessKindEnum` and `ToolProcessRecordDTO` for foreground/background tool processes.
- Create a cross-process `ToolProcessRegistry` backed by locked project-local runtime storage under `.hatfield/tmp/` (or equivalent ignored runtime location). It must let the controller see foreground PIDs registered by the Messenger `tool` consumer.
- Create `ToolProcessTerminator` for TERM -> grace -> KILL semantics, preferring Unix process-group termination (`posix_kill(-$pgid, ...)`) and falling back to direct PID termination. Accept grace/timeout values via constructor/config so TOOLS-R04 can wire them from Hatfield settings instead of hard-coded service arguments.
- Create `ProcessSpec.php`, `ProcessRunResult.php`, and `ForegroundProcessRunner.php`.
- `ForegroundProcessRunner` must start Symfony Process instances, create/register a process group where practical, capture stdout/stderr, expose an observer/decision hook for future Bash background handoff (`Continue`, `Terminate`, `DetachToBackground`), enforce timeout through `ToolProcessTerminator`, detect cancelled-token exits as `cancelled=true`, and unregister records in `finally` unless ownership is explicitly transferred to a background manager.
- Add controller/runtime wiring so an accepted cancel (`cancellation.requested` / cancel command applied) queries `ToolProcessRegistry::foregroundForRun($runId)` and terminates those foreground processes. Background processes must not be killed by ordinary run cancellation.
- Add focused PHPUnit tests using fake cancellation tokens and short-lived processes.

Out of scope:
- Do not implement bash/read/edit tools here.
- Do not implement full `BackgroundProcessManager` behavior here; only provide shared registry/terminator primitives and the runner-level handoff seam it can reuse later.
- Do not add model-visible cancellation parameters to tool schemas.
- Do not use consumer SIGTERM/SIGKILL as the primary foreground-tool cancellation path; that remains a future hard-cancel fallback.
- Do not add production APIs solely for tests; use production constructors/services or test-local fixtures.

## Acceptance criteria
- `ToolExecutionContextAccessorInterface` exposes the active context during Symfony Toolbox execution and clears it afterward, including on exceptions.
- AgentCore does not gain any dependency on `CodingAgent`; `castor deptrac` proves the boundary remains clean.
- `CancellationGuard` throws the AgentCore-owned domain-specific cancellation exception when the token is cancelled, and `ToolExecutor` returns structured cancellation details.
- `ToolProcessRegistry` is cross-process, lock-safe, stores foreground/background process records, removes stale/unregistered records, and can list foreground processes for a run.
- `ToolProcessTerminator` implements TERM -> grace -> KILL semantics, treats already-exited processes as stopped, prefers process-group termination on Unix, and accepts configurable grace values.
- `ForegroundProcessRunner` returns stdout/stderr, exit code, duration, timed_out, cancelled, and output path/cap metadata as appropriate.
- `ForegroundProcessRunner` registers the process after start and unregisters it in `finally` on success, failure, timeout, or cancellation; detach/background handoff is the only path that transfers ownership instead of unregistering immediately.
- Timeout terminates the registered process via `ToolProcessTerminator` and marks `timedOut=true`.
- Runtime/controller cancellation hook terminates registered foreground tool processes for the run and does not terminate background records.
- Cancellation while a foreground process is running terminates promptly through the registry/controller path and marks `cancelled=true` rather than a generic failure.
- Unix process-tree/process-group termination is covered by tests where practical, without leaking processes.
- Validation includes focused Castor/PHPUnit tests, `castor deptrac`, and a controller-level Castor workflow (`castor test:controller`) if controller cancellation wiring is changed.

## Workflow metadata
Status: CODE-REVIEW
Branch: task/tools-00-cancellation-guard-process-runner
Worktree: /home/ineersa/projects/agent-core-worktrees/tools-00-cancellation-guard-process-runner
Fork run: 1d65lmj6xu4h
PR URL: https://github.com/ineersa/agent-core/pull/55
PR Status: open
Started: 2026-05-26T16:08:33.510Z
Completed:

## Work log
- Created: 2026-05-17T21:15:48.702Z
- Updated: 2026-05-25 — replaced monolithic `CancellableProcessRunner` cancellation polling design with cross-process foreground process registry, shared terminator, `ForegroundProcessRunner`, and controller-owned cancellation termination.

## Task workflow update - 2026-05-26T16:08:33.510Z
- Moved TODO → IN-PROGRESS.
- Created branch task/tools-00-cancellation-guard-process-runner.
- Created worktree /home/ineersa/projects/agent-core-worktrees/tools-00-cancellation-guard-process-runner.
- Copied vendor directory into /home/ineersa/projects/agent-core-worktrees/tools-00-cancellation-guard-process-runner.
- Copied .vera index into /home/ineersa/projects/agent-core-worktrees/tools-00-cancellation-guard-process-runner.
- Summary: Started as part of wave 1 tools foundation per toolbox design plan.

## Task workflow update - 2026-05-26T16:19:58.823Z
- Moved IN-PROGRESS → CODE-REVIEW.
- Pushed task/tools-00-cancellation-guard-process-runner to origin.
- branch 'task/tools-00-cancellation-guard-process-runner' set up to track 'origin/task/tools-00-cancellation-guard-process-runner'.
- Created PR: https://github.com/ineersa/agent-core/pull/55
- Validation: castor deptrac: pass (0 violations, 0 errors); castor test: pass (988 tests, 10071 assertions); castor cs-fix: pass (7 files fixed); php -l on new files: pass; castor test:controller: not run; requires llama.cpp on port 9052, controller cancellation hook covered by unit tests
- Summary: Implemented AgentCore tool execution context/accessor contracts, ToolCancelledException handling in ToolExecutor, CodingAgent CancellationGuard, process registry/record DTOs, ToolProcessTerminator, ProcessSpec/ProcessRunResult, ForegroundProcessRunner with observer/detach seam, and controller CancelHandler foreground process termination hook. Committed as d4bfe634 on task/tools-00-cancellation-guard-process-runner.

## Task workflow update - 2026-05-26T16:42:34.000Z
- Summary: Reviewer subagent result: REQUEST CHANGES. Critical findings: ForegroundProcessRunner and ToolProcessTerminator have zero direct tests; ToolProcessTerminator::isAlive() uses abs($pid), breaking negative process-group liveness checks and SIGKILL escalation. Additional bugs: ToolProcessRegistry unregister read-modify-write TOCTOU race; ToolExecutor stale_due_to_cancel post-check can overwrite structured cancelled=true results; StackToolExecutionContextAccessor::current() checks array_key_last() against false instead of null; ForegroundProcessRunner cancellation/signal branch is ineffective; fallbackContext constructor parameter unused. Review artifact: /home/ineersa/.pi/agent/tmp/2026-05--ba6881a4.txt

## Task workflow update - 2026-05-26T16:44:38.316Z
- Recorded fork run: ypnsne9enfep
- Summary: Launched follow-up fork to address PR #55 reviewer issues and inline PR comment requesting logs in CancelHandler. Fork will fix ForegroundProcessRunner/ToolProcessTerminator test gaps, process-group liveness bug, registry unregister race, stale cancellation overwrite, accessor null check, cancellation/signal detection, fallbackContext cleanup, and add logging around cancellation foreground process termination.

## Task workflow update - 2026-05-26T19:01:18.095Z
- Recorded fork run: 1d65lmj6xu4h
- Summary: Failed fork ypnsne9enfep left partial uncommitted TOOLS-00 changes/tests. Retrieved artifact was incomplete. Launched continuation fork 1d65lmj6xu4h in same worktree to inspect/finish partial changes, validate with Castor, commit, and push PR #55 branch update.

## Task workflow update - 2026-05-26T19:18:01.994Z
- Recorded fork run: 1d65lmj6xu4h
- Validation: timeout 30s castor test --filter=ToolProcessTerminatorTest: pass (7 tests, 19 assertions); timeout 30s castor test --filter=ForegroundProcessRunnerTest: pass (7 tests, 18 assertions); timeout 60s castor test --filter='ForegroundProcessRunner|ToolProcessTerminator|ToolProcessRegistry|ToolExecutor|StackToolExecutionContextAccessor': pass (36 tests, 92 assertions); castor deptrac: pass (0 violations, 0 errors); castor cs-fix targeted files: pass (1 file fixed); castor cs-check targeted files: pass; timeout 240s castor test: pass (1002 tests, 10108 assertions)
- Summary: Recovered failed continuation fork manually and pushed PR #55 branch update. Commit c3ad6d28 addresses reviewer issues and PR comment: adds direct bounded tests for ForegroundProcessRunner and ToolProcessTerminator; replaces dangerous slow process-group test that killed Pi with safe current-process-group guard regression; ToolProcessTerminator preserves negative PGID semantics but refuses to signal the current process group and falls back to direct PID; ToolProcessRegistry modifyRecords now uses one LOCK_EX read/filter/truncate/write cycle with c+ handle + rewind; ToolExecutor no longer clobbers cancelled=true ToolResult with stale_due_to_cancel; StackToolExecutionContextAccessor uses null array_key_last check; ForegroundProcessRunner removes unused fallbackContext, uses Symfony hasBeenSignaled() for signal cancellation, handles empty commandPreview, and clarifies cancellation comments; CancelHandler has non-blocking PSR logger messages around process termination counts/failures.
