# EXT-HOOK-03 AgentCore ToolExecutor interception seam

## Goal
Plan: `.pi/plans/extension-tool-hooks-safeguard-plan.md`

Add an AgentCore-owned interception seam in the real tool execution choke point. This must not make AgentCore depend on CodingAgent or ExtensionApi directly.

Depends on: `EXT-HOOK-01` for public design context, but implementation should use AgentCore-local contracts/DTOs.

Scope:
- Add an AgentCore contract for before/after tool call interception.
- Add AgentCore-local interception result/value objects.
- Wire `ToolExecutor` to optionally invoke the interceptor after active tool allowlist checks and before actual toolbox handler execution.
- Let after-hooks adjust final tool results before idempotency/result wrapping.

## Acceptance criteria
- `ToolExecutor` invokes a before-tool interceptor after allowlist checks and before handler execution.
- Blocked calls do not invoke the underlying handler and return a structured error result.
- Replace-result decisions do not invoke the underlying handler and return the supplied result.
- After-tool interception can adjust/replace result content/details/error state after success or failure.
- Interceptor exceptions become safe blocked/error tool results rather than process crashes.
- AgentCore does not depend on CodingAgent or ExtensionApi.
- Validation with Castor: `castor test --filter ToolExecutor`; `castor deptrac`.

## Workflow metadata
Status: CODE-REVIEW
Branch: task/ext-hook-03-agentcore-tool-interception-seam
Worktree: /home/ineersa/projects/agent-core-worktrees/ext-hook-03-agentcore-tool-interception-seam
Fork run: tgxxbwlli6b2
PR URL: https://github.com/ineersa/agent-core/pull/66
PR Status: open
Started: 2026-05-29T21:36:51.169Z
Completed:

## Work log
- Created: 2026-05-29T20:49:48.713Z

## Task workflow update - 2026-05-29T21:36:51.169Z
- Moved TODO → IN-PROGRESS.
- Created branch task/ext-hook-03-agentcore-tool-interception-seam.
- Created worktree /home/ineersa/projects/agent-core-worktrees/ext-hook-03-agentcore-tool-interception-seam.
- Copied vendor directory into /home/ineersa/projects/agent-core-worktrees/ext-hook-03-agentcore-tool-interception-seam.
- Copied .vera index into /home/ineersa/projects/agent-core-worktrees/ext-hook-03-agentcore-tool-interception-seam.
- Summary: Starting EXT-HOOK-03. Scope is AgentCore-only ToolExecutor interception seam: contracts/DTOs under AgentCore, optional interceptor dependency, before/after execution integration after allowlist and around toolbox handler execution, no CodingAgent/ExtensionApi/SafeGuard/TUI dependencies.

## Task workflow update - 2026-05-29T21:37:30.297Z
- Recorded fork run: tgxxbwlli6b2
- Summary: Fork launched to implement EXT-HOOK-03 in /home/ineersa/projects/agent-core-worktrees/ext-hook-03-agentcore-tool-interception-seam. Instructions require startup cwd/branch verification, AgentCore-only contracts/DTOs, optional ToolExecutor interceptor dependency, before-hook after allowlist/before handler, after-hook around final domain ToolResult including handler failures, tests for block/replace/order/exception behavior, Castor validation, and commit on task branch.

## Task workflow update - 2026-05-29T21:44:52.341Z
- Recorded fork run: tgxxbwlli6b2
- Validation: castor test --filter ToolExecutor: OK (18 tests, 74 assertions); castor deptrac: OK (0 violations); castor cs-check on changed files: OK; castor cs-check --path src/AgentCore/ --path tests/AgentCore/Application/Handler/: FAIL due 10 pre-existing unrelated files under tests/AgentCore/Application/Handler (ExecutionFailureDrillTest, RunMetricsTest, ToolBatchCollector*, ExecutionWorkerTest, RunLockManagerTest, HookDispatcherContractTest, CommandRouterContractTest, InMemoryToolBatchStoreTest, ReplayServiceTest); working tree remained clean
- Summary: Fork completed and was reviewed. Final commit in worktree: 609ae20d (EXT-HOOK-03 Add AgentCore tool interception seam). Adds AgentCore-only ToolCallInterceptorInterface plus ToolCall/ToolResult interception decision DTOs/enums, wires optional ToolExecutor interceptor after allowlist and around toolbox handler execution, converts toolbox exceptions to ToolResult before after-interceptor, and keeps outer execute() catch as final safety net. No CodingAgent/ExtensionApi/SafeGuard/TUI dependencies introduced.

## Task workflow update - 2026-05-29T21:45:12.006Z
- Moved IN-PROGRESS → CODE-REVIEW.
- Pushed task/ext-hook-03-agentcore-tool-interception-seam to origin.
- branch 'task/ext-hook-03-agentcore-tool-interception-seam' set up to track 'origin/task/ext-hook-03-agentcore-tool-interception-seam'.
- Created PR: https://github.com/ineersa/agent-core/pull/66
- Validation: castor test --filter ToolExecutor: OK (18 tests, 74 assertions); castor deptrac: OK (0 violations); castor cs-check on the 7 changed files: OK; Note: broad castor cs-check --path src/AgentCore/ --path tests/AgentCore/Application/Handler/ reports 10 pre-existing unrelated CS issues in other Handler tests; changed files are clean
- Summary: EXT-HOOK-03 is implemented and committed on task branch at 609ae20d. Adds AgentCore-only ToolCallInterceptorInterface and before/after interception decision DTOs/enums, wires optional ToolExecutor interceptor after active tool allowlist and around toolbox handler execution, and ensures interceptor/toolbox exceptions become safe ToolResult values. No CodingAgent, ExtensionApi, SafeGuard, TUI, or approval-flow dependencies were introduced.

## Task workflow update - 2026-05-29T22:07:11.524Z
- Updated PR URL: https://github.com/ineersa/agent-core/pull/66
- Updated PR Status: open
- Validation: castor test --filter RegistryBackedToolbox: OK (14 tests, 31 assertions); castor test --filter ToolExecutor: OK (11 tests, 35 assertions); castor deptrac: OK (0 violations); castor cs-check on changed files: OK
- Summary: PR #66 was revised after review feedback to avoid inventing a custom AgentCore interceptor/event surface. The branch was reset from the earlier ToolExecutor interceptor implementation and force-pushed at 93c01de0. Current implementation reuses Symfony AI toolbox lifecycle events by making RegistryBackedToolbox dispatch ToolCallRequested, ToolCallArgumentsResolved, ToolCallSucceeded, and ToolCallFailed through the shared Symfony event dispatcher. ToolCallRequested::deny() and ::setResult() can now block or replace execution before the handler runs. ToolExecutor is no longer modified, and no custom ToolCallInterceptorInterface or AgentCore tool execution events are added.
