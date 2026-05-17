# TOOLS-00 Implement tool cancellation context and cancellable process runner

## Goal
## Goal
Implement shared cancellation and process-execution primitives for CodingAgent toolbox tools.

Plan source: `.pi/plans/toolbox-design-plan.md`.

Scope:
- Create `src/CodingAgent/Tool/ToolExecutionContext.php` and a concrete context DTO/service used for one toolbox invocation.
- Create `src/CodingAgent/Tool/ToolExecutionContextAccessor.php` to expose the current context to app-owned `#[AsTool]` services while Symfony Toolbox executes them.
- Update `ToolExecutor` to wrap `FaultTolerantToolbox::execute()` in the current `ToolExecutionContext`.
- Create `src/CodingAgent/Tool/CancellationGuard.php` and `ToolCancelledException` for cooperative checkpoints.
- Create `src/CodingAgent/Tool/ProcessSpec.php`, `ProcessRunResult.php`, and `CancellableProcessRunner.php`.
- `CancellableProcessRunner` must own process start, output capture, timeout enforcement, cancellation polling, and TERM -> grace -> KILL termination.
- Prefer process-group/session termination on Unix so child processes do not survive cancellation; fallback to direct process termination where process groups are unavailable.
- Add focused PHPUnit tests using fake cancellation tokens and short-lived processes.

Out of scope:
- Do not implement bash/read/edit tools here.
- Do not implement background process registry here.
- Do not add model-visible cancellation parameters to tool schemas.
- Do not add production APIs solely for tests; use production constructors/services or test-local fixtures.

## Acceptance criteria
- `ToolExecutionContextAccessor` exposes the active context during Symfony Toolbox execution and clears it afterward, including on exceptions.
- `CancellationGuard` throws a domain-specific cancellation exception when the token is cancelled.
- `CancellableProcessRunner` returns stdout/stderr, exit code, duration, timed_out, cancelled, and output path/cap metadata as appropriate.
- Timeout terminates the process and marks `timedOut=true`.
- Cancellation while a process is running terminates the process promptly and marks `cancelled=true` rather than a generic failure.
- Unix process-tree/process-group termination is covered by tests where practical, without leaking processes.
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
- Created: 2026-05-17T21:15:48.702Z
