# EXT-HOOK-04 CodingAgent adapter from ExtensionApi hooks to ToolExecutor

## Goal
Plan: `.pi/plans/extension-tool-hooks-safeguard-plan.md`

Connect public extension hook registrations to the AgentCore interception seam through a CodingAgent adapter. This is the runtime wiring that makes `registerToolCallHook()` and `registerToolResultHook()` affect real tool execution.

Depends on: `EXT-HOOK-02`, `EXT-HOOK-03`.

Scope:
- Add a CodingAgent dispatcher/adapter that implements the AgentCore tool interception contract.
- Convert internal tool call/result data into public ExtensionApi DTOs.
- Convert public hook decisions back into AgentCore interception results/tool results.
- Register the adapter as the optional interceptor consumed by `ToolExecutor`.

## Acceptance criteria
- Public `ToolCallContextDTO` receives correct tool call id, tool name, arguments, order index, run id, turn number, cwd, and metadata where available.
- Multiple before-hooks run in registration order; the first non-allow decision wins.
- Public `Block` decisions map to structured error tool results.
- Public `ReplaceResult` decisions map to non-handler tool results.
- Result hooks run in registration order, each seeing the latest result state.
- A real extension hook registered through `ExtensionApiInterface` can block a fake/fixture tool call through the `ToolExecutor` path in tests.
- Validation with Castor: `castor test --filter ToolHookDispatcher`; `castor test --filter ToolExecutor`; `castor deptrac`.

## Workflow metadata
Status: CODE-REVIEW
Branch: task/ext-hook-04-extension-hook-dispatcher-wiring
Worktree: /home/ineersa/projects/agent-core-worktrees/ext-hook-04-extension-hook-dispatcher-wiring
Fork run: a0iyc35feygf
PR URL: https://github.com/ineersa/agent-core/pull/67
PR Status: open
Started: 2026-05-29T22:15:46.425Z
Completed:

## Work log
- Created: 2026-05-29T20:49:57.913Z

## Task workflow update - 2026-05-29T22:15:46.425Z
- Moved TODO → IN-PROGRESS.
- Created branch task/ext-hook-04-extension-hook-dispatcher-wiring.
- Created worktree /home/ineersa/projects/agent-core-worktrees/ext-hook-04-extension-hook-dispatcher-wiring.
- Copied vendor directory into /home/ineersa/projects/agent-core-worktrees/ext-hook-04-extension-hook-dispatcher-wiring.
- Copied .vera index into /home/ineersa/projects/agent-core-worktrees/ext-hook-04-extension-hook-dispatcher-wiring.
- Summary: Starting EXT-HOOK-04 after EXT-HOOK-03 merged. Note: EXT-HOOK-03 final design reuses Symfony AI toolbox lifecycle events from RegistryBackedToolbox; EXT-HOOK-04 should adapt ExtensionHookRegistry hooks to Symfony AI ToolCallRequested and observational result events, not to a custom AgentCore ToolExecutor interceptor.

## Task workflow update - 2026-05-29T22:20:29.314Z
- Recorded fork run: a0iyc35feygf
- Summary: Fork launched to finish EXT-HOOK-04 from the worktree. Note: parent had started WIP directly by mistake; fork is instructed to verify worktree/branch, review/refine existing uncommitted WIP, keep design based on Symfony AI ToolCallRequested/ToolCallSucceeded/ToolCallFailed events, avoid custom AgentCore interceptors/events, run Castor validations, and commit final work.

## Task workflow update - 2026-05-29T22:23:31.575Z
- Moved IN-PROGRESS → CODE-REVIEW.
- Pushed task/ext-hook-04-extension-hook-dispatcher-wiring to origin.
- branch 'task/ext-hook-04-extension-hook-dispatcher-wiring' set up to track 'origin/task/ext-hook-04-extension-hook-dispatcher-wiring'.
- Created PR: https://github.com/ineersa/agent-core/pull/67
- Validation: castor test --filter ExtensionToolHookEventSubscriber: passed (17 tests, 63 assertions); castor test --filter ToolExecutor: passed (11 tests, 35 assertions); castor test: passed (1204 tests, 10668 assertions, 0 errors/failures; command exit 1 due pre-existing PHPUnit notices in CancelListenerTest); castor deptrac: passed (0 violations); castor cs-check on changed files: passed
- Summary: EXT-HOOK-04 completed in fork run a0iyc35feygf at commit 7c0cfdf2. Added CodingAgent ExtensionToolHookEventSubscriber adapting public ExtensionApi ToolCallHookInterface hooks to Symfony AI ToolCallRequested events; first non-allow decision wins, Block/ReplaceResult skip handlers via setResult. ToolResultHookInterface hooks run on ToolCallSucceeded/ToolCallFailed in registration order with latest local state, but remain observational because Symfony AI result events are readonly. ToolContext now carries orderIndex and ToolExecutor passes the shared context accessor for hook context enrichment. No custom AgentCore ToolExecutor interceptor/events introduced.
