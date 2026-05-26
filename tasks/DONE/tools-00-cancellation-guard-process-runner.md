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
Status: DONE
Branch: task/tools-00-cancellation-guard-process-runner
Worktree: /home/ineersa/projects/agent-core-worktrees/tools-00-cancellation-guard-process-runner
Fork run: dxm1z8gqhxl9
PR URL: https://github.com/ineersa/agent-core/pull/55
PR Status: merged
Started: 2026-05-26T16:08:33.510Z
Completed: 2026-05-26T23:01:15.694Z

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

## Task workflow update - 2026-05-26T19:39:46.879Z
- Validation: gh pr view 55 and gh api pulls/55/comments pulled 7 inline comments from PR #55.
- Summary: Pulled PR #55 review comments for planning. Comments: CancelHandler needs logs (appears already addressed in latest commit); hardcoded services.yaml values must come from Hatfield settings; process primitives likely should move out of CodingAgent\Tool namespace; ForegroundProcessRunner synchronous wait raises parallel-tool-call architecture concern; ToolProcessRecordDTO should use Symfony Serializer normalize/denormalize instead of manual toArray/fromArray; ToolProcessRegistry needs detailed explanation/justification; ToolProcessTerminator should become reusable process utility used elsewhere similar to PathResolver.

## Task workflow update - 2026-05-26T20:13:23.415Z
- Validation: cd /home/ineersa/projects/ai && rg -n "Fiber|fiber|parallel.*tool" src examples docs -S (no current matches); gh pr view 1829 --repo symfony/ai: state OPEN; files include FiberToolExecutionStrategy.php and AgentProcessor executionStrategy injection
- Summary: Investigated Symfony AI parallel tool calls PR after user asked. In /home/ineersa/projects/ai current main has no Fiber/parallel execution classes and AgentProcessor still executes tool calls sequentially. Upstream symfony/ai PR #1829 '[Agent] Add tool execution strategies for bulk execution' is OPEN, not merged; it adds ToolExecutionStrategyInterface plus SequentialToolExecutionStrategy and FiberToolExecutionStrategy. Fiber strategy is cooperative only and does not provide OS/process-level parallelism for blocking foreground subprocess waits. User also objected to JSONL+flock ToolProcessRegistry; recommended replacing it with a small SQLite-backed process table using existing Doctrine DBAL connection instead of manual file locks.

## Task workflow update - 2026-05-26T20:19:08.561Z
- Validation: task_list TODO/IN-PROGRESS/CODE-REVIEW checked: no explicit parallel tool execution task found.
- Summary: User reiterated PR #55 review-plan items and clarified parallel tool execution should be treated as separate work if already tracked. Current task board has related pieces (TOOLS-R04 settings max_parallelism, ToolBatchCollector existing batch logic, TOOLS-R03 toolsRef/allowlist), but no explicit task covering true parallel tool execution with multiple tool consumers and persistent cross-process batch state. PR #55 follow-up should not attempt to solve true parallel execution; it should only ensure foreground runner is safe per worker and document that parallelism belongs above it. User also prefers replacing file/lock process registry with SQLite table instead of locks.

## Task workflow update - 2026-05-26T20:22:10.595Z
- Recorded fork run: h43h212je95k
- Summary: Launched PR #55 follow-up fork to fix all remaining review items: move process primitives out of Tool namespace, rename generic ProcessTerminator and reuse it where practical, settings-backed hardcoded service args, replace JSONL/flock process registry with SQLite/Doctrine DBAL table, remove manual record serialization where possible, add registry architecture docs, preserve safe process-group guards/tests, and explicitly defer true parallel execution to new TOOLS-R05 task.

## Task workflow update - 2026-05-26T21:22:11.748Z
- Validation: castor test: 1038 tests, 10148 assertions, 0 errors, 0 failures; castor deptrac: 0 violations, 0 errors, 368 uncovered, 761 allowed; castor cs-check: ok
- Summary: Completed all PR #55 review fixes: moved process primitives to CodingAgent\Process namespace, replaced JSONL+flock registry with SQLite-backed Doctrine DBAL table, created ToolSettings DTO, renamed ToolProcessTerminator to ProcessTerminator (used in ConsumerSupervisor), added tools settings keys to defaults/docs, wired service args from settings, added parallel-execution documentation, updated deptrac AppProcess layer. All 1038 existing tests pass, deptrac 0 violations, cs-check clean.

## Task workflow update - 2026-05-26T21:56:59.608Z
- Validation: cd /home/ineersa/projects/agent-core-worktrees/tools-00-cancellation-guard-process-runner && castor cache:clear: pass; castor test --filter='ToolExecutor|ToolExecutionPolicyResolver|ToolSettings|ToolProcessRegistry|ProcessTerminator|ForegroundProcessRunner|ConsumerSupervisor': pass (31 tests, 84 assertions); castor deptrac: pass (violations=0, errors=0, uncovered=370, allowed=761); castor cs-check: pass; castor test: pass (1038 tests, 10148 assertions, 0 errors, 0 failures); git push origin task/tools-00-cancellation-guard-process-runner: pushed 3ddeadd4 and 11c3cd12 to PR #55
- Summary: PR #55 follow-up completed and pushed. Fork h43h212je95k produced commit 3ddeadd4, but left config/docs/test modifications uncommitted and left settings wiring as container parameters only. Parent completed the remaining fix in worktree and pushed commit 11c3cd12. Final branch now moves process primitives to Ineersa\CodingAgent\Process, renames ToolProcessTerminator to ProcessTerminator, replaces JSONL/flock registry with Doctrine DBAL SQLite table `hatfield_tool_processes`, introduces AgentCore ToolExecutionSettingsInterface implemented by CodingAgent ToolSettings, wires ToolExecutor/ToolExecutionPolicyResolver/ProcessTerminator from merged Hatfield settings instead of hardcoded YAML scalar defaults, uses ProcessTerminator in ConsumerSupervisor, documents settings and parallelism deferral to TOOLS-R05, and keeps AgentCore free of CodingAgent dependencies.

## Task workflow update - 2026-05-26T22:11:20.443Z
- Recorded fork run: rlgxp26xowvh
- Summary: Launched follow-up fork rlgxp26xowvh to replace PR #55 ad-hoc DBAL/ensureTable ToolProcessRegistry with proper Doctrine ORM entity + repository + migration, and to run migrations on Hatfield startup before consumers/runtime use the SQLite database. Fork instructed to preserve existing process namespace/settings/terminator fixes and keep tests fast/bounded.

## Task workflow update - 2026-05-26T22:22:29.233Z
- Recorded fork run: 9khjuabu18vf
- Summary: Launched follow-up fork 9khjuabu18vf to abandon the ORM/process-registry direction and simplify TOOLS-00 to minimal cancellation context primitives: discard uncommitted ORM changes, remove foreground registry/runner/terminator infrastructure, keep only current tool execution context bridge plus ToolCancelledException handling, simplify ConsumerSupervisor shutdown, update tests, validate, commit, and push PR #55 branch.

## Task workflow update - 2026-05-26T22:28:06.518Z
- Recorded fork run: 9khjuabu18vf
- Validation: castor cache:clear — passed; castor test --filter="Container|ToolExecutor|ToolContext|CancellationGuard|ToolSettings|Context" — 107 tests, 294 assertions, passed; castor deptrac — 0 violations, 351 uncovered, 758 allowed; castor cs-check — passed; castor test — 1009 tests, 10055 assertions, passed
- Summary: Follow-up fork 9khjuabu18vf completed and pushed commit dfedb380 to task/tools-00-cancellation-guard-process-runner. Simplified TOOLS-00 by discarding abandoned ORM/process-registry work, deleting foreground process runner/registry/terminator/spec/result infrastructure and tests, removing Process services/layer/settings, simplifying CancelHandler to cancel+emit only, and fixing ConsumerSupervisor shutdown to use Symfony Process::stop($shutdownGraceSeconds) without the erroneous double-SIGTERM fallback. Remaining TOOLS-00 surface is minimal cancellation context bridge (ToolContext, StackToolExecutionContextAccessor, ToolExecutionContext contracts, ToolCancelledException, retained small CancellationGuard) plus ToolExecutor wrapping/cancellation result handling.

## Task workflow update - 2026-05-26T22:42:14.120Z
- Recorded fork run: dxm1z8gqhxl9
- Summary: Launched follow-up fork dxm1z8gqhxl9 to address latest PR #55 comments on simplified TOOLS-00: remove CancellationGuard, remove unused ToolCancelledException, remove unnecessary ToolExecutionContextAccessorInterface/concrete-only if feasible, clarify ConsumerSupervisor shutdown as controller shutdown not run cancellation, and replace ToolSettings manual array walking with typed DTO denormalization via Symfony Serializer while keeping scope tight.

## Task workflow update - 2026-05-26T22:51:17.407Z
- Recorded fork run: dxm1z8gqhxl9
- Validation: castor cache:clear: OK; castor test --filter="ToolExecutor|ToolContext|StackToolExecutionContextAccessor|ToolSettings|ConsumerSupervisor": OK (13 tests, 34 assertions); castor deptrac: OK (0 violations, 352 uncovered, 758 allowed); castor cs-check: OK after cs-fix; castor test: OK (998 tests, 10044 assertions)
- Summary: Follow-up fork dxm1z8gqhxl9 completed and pushed commit 88e3fdfd to PR #55. It removed CancellationGuard, ToolCancelledException, and ToolExecutionContextAccessorInterface; refactored ToolSettings to denormalize tools.execution into typed ToolExecutionConfig DTO via Symfony DenormalizerInterface; clarified ConsumerSupervisor shutdown as controller/runtime shutdown rather than run cancellation; kept only minimal current ToolContext/StackToolExecutionContextAccessor cancellation-token access.

## Task workflow update - 2026-05-26T22:56:25.845Z
- Validation: castor test --filter="ToolExecutor|ToolContext|StackToolExecutionContextAccessor|ToolSettings|ConsumerSupervisor": ok (tests=13, assertions=34); castor deptrac: ok (violations=0, errors=0, uncovered=352, allowed=758); castor cs-check: ok (files_fixed=0); castor test: ok (tests=998, assertions=10044)
- Summary: Addressed final simplification question by removing ToolExecutionContextInterface as well. ToolContext is now the concrete final context DTO, and StackToolExecutionContextAccessor types current()/requireCurrent()/with() directly to ToolContext.

## Task workflow update - 2026-05-26T22:59:11.133Z
- Validation: castor cache:clear: ok; castor test --filter="ToolExecutor|ToolContext|StackToolExecutionContextAccessor|ToolSettings|OutputCap|AppConfig|SettingsPathResolver|HatfieldSessionStore|ExtensionManager": ok (tests=93, assertions=219); castor deptrac: ok (violations=0, errors=0, uncovered=364, allowed=760); castor cs-check: ok (files_fixed=0); castor test: ok (tests=1026, assertions=10095); gh pr view 55 --json mergeStateStatus: CLEAN
- Summary: Addressed final question by removing ToolExecutionContextInterface. Pushed commit 9711d3bb making ToolContext the concrete final DTO and typing StackToolExecutionContextAccessor directly to ToolContext. Then merged current main into PR #55 as aa4a07db, resolving TOOLS-02/AppConfig conflicts by combining tools.execution and tools.output_cap into one typed ToolsConfig DTO and making ToolSettings read appConfig->tools->execution instead of AppConfig::raw['tools']. PR #55 merge state is CLEAN.

## Task workflow update - 2026-05-26T23:01:15.695Z
- Moved CODE-REVIEW → DONE.
- Merged task/tools-00-cancellation-guard-process-runner into integration checkout.
- Merge made by the 'ort' strategy.
 .hatfield/settings.yaml                            | 12 +++
 config/hatfield.defaults.yaml                      | 75 ++++++++---------
 config/services.yaml                               | 27 +++++--
 docs/settings.md                                   | 42 ++++++++++
 .../Handler/ToolExecutionPolicyResolver.php        | 14 ++++
 src/AgentCore/Application/Handler/ToolExecutor.php | 73 ++++++++++++++---
 .../Tool/StackToolExecutionContextAccessor.php     | 42 ++++++++++
 src/AgentCore/Application/Tool/ToolContext.php     | 53 ++++++++++++
 .../Tool/ToolExecutionSettingsInterface.php        | 22 +++++
 src/CodingAgent/Config/ToolExecutionConfig.php     | 37 +++++++++
 src/CodingAgent/Config/ToolSettings.php            | 61 ++++++++++++++
 src/CodingAgent/Config/ToolsConfig.php             |  5 +-
 .../Controller/CommandHandler/CancelHandler.php    | 13 +++
 .../Runtime/Controller/ConsumerSupervisor.php      | 22 +++--
 .../Application/Handler/ToolExecutorTest.php       | 88 ++++++++++++++++++++
 .../Tool/StackToolExecutionContextAccessorTest.php | 93 ++++++++++++++++++++++
 .../AgentCore/Application/Tool/ToolContextTest.php | 37 +++++++++
 17 files changed, 655 insertions(+), 61 deletions(-)
 create mode 100644 src/AgentCore/Application/Tool/StackToolExecutionContextAccessor.php
 create mode 100644 src/AgentCore/Application/Tool/ToolContext.php
 create mode 100644 src/AgentCore/Contract/Tool/ToolExecutionSettingsInterface.php
 create mode 100644 src/CodingAgent/Config/ToolExecutionConfig.php
 create mode 100644 src/CodingAgent/Config/ToolSettings.php
 create mode 100644 tests/AgentCore/Application/Tool/StackToolExecutionContextAccessorTest.php
 create mode 100644 tests/AgentCore/Application/Tool/ToolContextTest.php
- Removed worktree /home/ineersa/projects/agent-core-worktrees/tools-00-cancellation-guard-process-runner.
- Pulled integration checkout: Merge made by the 'ort' strategy..
- Validation: PR #55 merged: b2e44ca16bf491770231c75a470cf5918fa31f1d; Final branch validation before merge: castor test ok (1026 tests, 10095 assertions); castor deptrac ok (0 violations); castor cs-check ok
- Summary: PR #55 merged. Final implementation is simplified TOOLS-00 cancellation context only: ToolContext concrete DTO, StackToolExecutionContextAccessor, ToolExecutionSettingsInterface/ToolSettings wiring, ConsumerSupervisor shutdown clarification, no CancellationGuard/ToolCancelledException/context accessor interface/process registry/foreground runner. Branch also merged current main and resolved TOOLS-02 typed ToolsConfig integration.
