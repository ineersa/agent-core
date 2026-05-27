# TOOLS-03 Implement simple write tool

## Goal
Implement the simple `write` tool.

Plan source: `.pi/plans/toolbox-design-plan.md`.

Dependencies:
- Depends on TOOLS-R02 (Hatfield tool definition convention) and TOOLS-R03 (registry-backed Toolbox and allowlist wiring).
- Depends on TOOLS-00 (`ToolExecutionContextInterface`, `CancellationGuard`).
- Depends on TOOLS-01 (`PathResolver`).

Scope:
- Replace/complete `src/CodingAgent/Tool/WriteFileTool.php`.
- Provide a Hatfield tool definition/provider for `write` instead of relying on `#[AsTool]` metadata.
- Register `write` as a permanent tool through the TOOLS-R02 built-in tool registrar/`ToolRegistryInterface`, including provider description, explicit JSON schema, prompt line, and concise guidelines. Execution flows through the TOOLS-R03 registry-backed Toolbox.
- Tool definition JSON schema should match `__invoke(string $path, string $content)`.
- Resolve the path with `PathResolver`.
- Check cancellation via `CancellationGuard` before filesystem mutation.
- `mkdir(dirname($path), recursive: true)` before writing.
- Write exact content with `file_put_contents`.
- Return text result: `Successfully wrote N bytes to <path>`.
- Add focused tests.

Out of scope:
- No read-before-write enforcement.
- No diff generation.
- No append mode.
- No create/update discrimination.

## Acceptance criteria
- `write` tool is discoverable through the registry-backed Symfony Toolbox metadata and present in `ToolRegistryInterface` permanent metadata.
- Tool creates missing parent directories and writes exact content.
- Tool overwrites existing files without requiring a prior read.
- Tool checks cancellation before writing and returns/throws the standard cancellation path when cancellation is already requested.
- Tool reports byte count and resolved path on success.
- Focused tests cover new file, nested directory creation, and overwrite.
- Focused tests pass with Castor/PHPUnit.

## Workflow metadata
Status: IN-PROGRESS
Branch: task/tools-03-write-tool
Worktree: /home/ineersa/projects/agent-core-worktrees/tools-03-write-tool
Fork run: 57jpdgouwxjh
PR URL:
PR Status:
Started: 2026-05-27T16:16:17.419Z
Completed:

## Work log
- Created: 2026-05-17T04:42:04.932Z

## Task workflow update - 2026-05-27T16:16:17.419Z
- Moved TODO → IN-PROGRESS.
- Created branch task/tools-03-write-tool.
- Created worktree /home/ineersa/projects/agent-core-worktrees/tools-03-write-tool.
- Copied vendor directory into /home/ineersa/projects/agent-core-worktrees/tools-03-write-tool.
- Copied .vera index into /home/ineersa/projects/agent-core-worktrees/tools-03-write-tool.
- Summary: Starting TOOLS-03. Note: task text is partially stale after TOOLS-00/R03 simplification: use ToolRuntime::run() / ambient ToolContext for cancellation checkpoints, not removed CancellationGuard or ToolExecutionContextInterface; use HatfieldToolProviderInterface provider/autoconfiguration through ToolRegistry constructor, not BuiltInToolRegistrar.

## Task workflow update - 2026-05-27T16:17:16.164Z
- Recorded fork run: 57jpdgouwxjh
- Summary: Launched implementation fork 57jpdgouwxjh in worktree /home/ineersa/projects/agent-core-worktrees/tools-03-write-tool. Fork prompt includes current post-TOOLS-R03/R05 architecture: implement WriteFileTool as ToolHandlerInterface + HatfieldToolProviderInterface, use ToolRuntime::run() for cancellation checkpoints, PathResolver for path normalization, registry/provider metadata, focused tests, and Castor validation.
