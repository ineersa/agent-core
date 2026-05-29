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
Status: TODO
Branch:
Worktree:
Fork run:
PR URL:
PR Status:
Started:
Completed:

## Work log
- Created: 2026-05-29T20:49:48.713Z
