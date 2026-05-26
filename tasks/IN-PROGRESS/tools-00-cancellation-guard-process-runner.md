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
Status: IN-PROGRESS
Branch: task/tools-00-cancellation-guard-process-runner
Worktree: /home/ineersa/projects/agent-core-worktrees/tools-00-cancellation-guard-process-runner
Fork run:
PR URL:
PR Status:
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
