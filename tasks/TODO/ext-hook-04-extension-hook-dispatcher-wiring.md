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
Status: TODO
Branch:
Worktree:
Fork run:
PR URL:
PR Status:
Started:
Completed:

## Work log
- Created: 2026-05-29T20:49:57.913Z
