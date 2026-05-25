# TOOLS-00 Implement tool cancellation context and foreground process termination primitives

## Goal
Implement shared cancellation and foreground process-execution primitives for CodingAgent toolbox tools.

Plan source: `.pi/plans/toolbox-design-plan.md`.

This task follows the async-runtime cancellation ladder: cooperative token checks are level 1; controller/runtime-owned foreground PID/process-group termination is level 2. Do not build a separate per-tool cancellation polling system.

Scope:
- Create `src/CodingAgent/Tool/ToolExecutionContext.php` and a concrete context DTO/service used for one toolbox invocation. Include run id, turn number, tool call id, tool name, timeout, and existing cancellation token access.
- Create `src/CodingAgent/Tool/ToolExecutionContextAccessor.php` with `current()`, `requireCurrent()`, and stack-safe `with()` helpers.
- Update `ToolExecutor` to wrap `FaultTolerantToolbox::execute()` in the current `ToolExecutionContext`.
- Create `src/CodingAgent/Tool/CancellationGuard.php` and `ToolCancelledException` for cooperative checkpoints in short app-owned tools.
- Create process-tracking value objects/enums such as `ToolProcessKindEnum` and `ToolProcessRecordDTO` for foreground/background tool processes.
- Create a cross-process `ToolProcessRegistry` backed by locked project-local runtime storage under `.hatfield/tmp/` (or equivalent ignored runtime location). It must let the controller see foreground PIDs registered by the Messenger `tool` consumer.
- Create `ToolProcessTerminator` for TERM -> grace -> KILL semantics, preferring Unix process-group termination (`posix_kill(-$pgid, ...)`) and falling back to direct PID termination.
- Create `ProcessSpec.php`, `ProcessRunResult.php`, and `ForegroundProcessRunner.php`.
- `ForegroundProcessRunner` must start Symfony Process instances, create/register a process group where practical, capture stdout/stderr, enforce timeout through `ToolProcessTerminator`, detect cancelled-token exits as `cancelled=true`, and unregister records in `finally`.
- Add controller/runtime wiring so an accepted cancel (`cancellation.requested` / cancel command applied) queries `ToolProcessRegistry::foregroundForRun($runId)` and terminates those foreground processes. Background processes must not be killed by ordinary run cancellation.
- Add focused PHPUnit tests using fake cancellation tokens and short-lived processes.

Out of scope:
- Do not implement bash/read/edit tools here.
- Do not implement full `BackgroundProcessManager` behavior here; only provide shared registry/terminator primitives it can reuse later.
- Do not add model-visible cancellation parameters to tool schemas.
- Do not use consumer SIGTERM/SIGKILL as the primary foreground-tool cancellation path; that remains a future hard-cancel fallback.
- Do not add production APIs solely for tests; use production constructors/services or test-local fixtures.

## Acceptance criteria
- `ToolExecutionContextAccessor` exposes the active context during Symfony Toolbox execution and clears it afterward, including on exceptions.
- `CancellationGuard` throws a domain-specific cancellation exception when the token is cancelled.
- `ToolProcessRegistry` is cross-process, lock-safe, stores foreground/background process records, removes stale/unregistered records, and can list foreground processes for a run.
- `ToolProcessTerminator` implements TERM -> grace -> KILL semantics, treats already-exited processes as stopped, and prefers process-group termination on Unix.
- `ForegroundProcessRunner` returns stdout/stderr, exit code, duration, timed_out, cancelled, and output path/cap metadata as appropriate.
- `ForegroundProcessRunner` registers the process after start and unregisters it in `finally` on success, failure, timeout, or cancellation.
- Timeout terminates the registered process via `ToolProcessTerminator` and marks `timedOut=true`.
- Runtime/controller cancellation hook terminates registered foreground tool processes for the run and does not terminate background records.
- Cancellation while a foreground process is running terminates promptly through the registry/controller path and marks `cancelled=true` rather than a generic failure.
- Unix process-tree/process-group termination is covered by tests where practical, without leaking processes.
- Validation includes focused Castor/PHPUnit tests, `castor deptrac`, and a controller-level Castor workflow (`castor test:controller`) if controller cancellation wiring is changed.

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
- Created: 2026-05-17T21:15:48.702Z
- Updated: 2026-05-25 — replaced monolithic `CancellableProcessRunner` cancellation polling design with cross-process foreground process registry, shared terminator, `ForegroundProcessRunner`, and controller-owned cancellation termination.
