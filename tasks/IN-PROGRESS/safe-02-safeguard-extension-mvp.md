# SAFE-02 SafeGuard extension MVP through ExtensionApi hooks

## Goal
Plan: `.pi/plans/extension-tool-hooks-safeguard-plan.md`

Implement SafeGuard as a real Hatfield extension using only the public ExtensionApi registration surface. This task wires the classifier into a tool call hook and proves it can block tool execution.

Depends on: `EXT-HOOK-04`, `SAFE-01`.

Scope:
- Add a bundled SafeGuard extension class implementing `HatfieldExtensionInterface`.
- Register a SafeGuard tool call hook via `ExtensionApiInterface::registerToolCallHook()`.
- Apply SafeGuard rules to `bash`, `read`, `write`, `edit`, and future path-reading/path-writing tools where arguments are recognizable.
- Return clear structured blocked results for denied operations.
- No interactive prompts in this MVP.

## Acceptance criteria
- SafeGuard extension class implements `Ineersa\Hatfield\ExtensionApi\HatfieldExtensionInterface`.
- SafeGuard registers only through `ExtensionApiInterface`; it does not special-case `ToolExecutor` or reach into internal registries directly.
- Dangerous bash commands are blocked through the real extension hook bridge.
- Protected reads are blocked through the real extension hook bridge.
- Writes/edits outside cwd are blocked through the real extension hook bridge.
- Blocked tool results are clear and LLM-visible with category/reason details.
- Integration tests prove enabling the SafeGuard extension blocks fixture tool calls through the real `ToolExecutor` path.
- Validation with Castor: `castor test --filter SafeGuard`; `castor test --filter ToolHookDispatcher`; `castor deptrac`.

## Workflow metadata
Status: IN-PROGRESS
Branch: task/safe-02-safeguard-extension-mvp
Worktree: /home/ineersa/projects/agent-core-worktrees/safe-02-safeguard-extension-mvp
Fork run:
PR URL:
PR Status:
Started: 2026-05-30T01:22:43.740Z
Completed:

## Work log
- Created: 2026-05-29T20:50:13.971Z

## Task workflow update - 2026-05-30T01:22:43.740Z
- Moved TODO → IN-PROGRESS.
- Created branch task/safe-02-safeguard-extension-mvp.
- Created worktree /home/ineersa/projects/agent-core-worktrees/safe-02-safeguard-extension-mvp.
- Copied vendor directory into /home/ineersa/projects/agent-core-worktrees/safe-02-safeguard-extension-mvp.
- Copied .vera index into /home/ineersa/projects/agent-core-worktrees/safe-02-safeguard-extension-mvp.
