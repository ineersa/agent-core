# TOOLS-06 Implement edit tool with built-in PatchRunner

## Goal
Implement the `edit` tool that applies standard unified diffs via GNU `patch`, combining the PatchRunner utility and tool registration in one task.

Plan source: `.pi/plans/toolbox-design-plan.md`.

Merged from TOOLS-05 (PatchRunner utility) — the original split depended on `ForegroundProcessRunner`/`ProcessSpec`/`ProcessRunResult` from TOOLS-00, which were removed during PR review. TOOLS-00 shipped with `ToolRuntime::runCancellableProcess()` and `CancellableProcessResult` instead, making a standalone PatchRunner task unnecessary overhead.

Dependencies:
- Depends on TOOLS-00 (`ToolRuntime`, `CancellableProcessResult`, `ToolContext`).
- Depends on TOOLS-01 (`PathResolver`).
- Depends on TOOLS-R02 (`HatfieldToolProviderInterface`, `ToolDefinitionDTO`).
- Depends on TOOLS-R03 (`RegistryBackedToolbox`, `ToolHandlerInterface`).

Scope:
- Replace/complete `src/CodingAgent/Tool/EditFileTool.php`.
- Implement `HatfieldToolProviderInterface` for automatic registration (same pattern as `WriteFileTool`, `ViewImageTool`).
- Tool definition JSON schema: `{path: string, patch: string}`.
- Full patch cycle:
  1. Resolve path via `PathResolver`.
  2. Verify target file exists (reject missing → "use write for new files").
  3. Write patch content to temp file (never interpolate into shell).
  4. Dry-run: `patch -u -F3 -l -N --dry-run --posix -o /dev/null <target> <patch>` via `ToolRuntime::runCancellableProcess()`.
     - On failure: return stderr verbatim, original file untouched, clean up temp.
  5. Apply: `patch -u -F3 -l -N -o <temp-out> <target> <patch>` via `ToolRuntime::runCancellableProcess()`.
  6. Atomic replace: `rename()` temp-out → target.
  7. Stats: count `+`/`-` lines from patch content for "N additions, M deletions".
  8. Clean up temp patch file.
  9. Return `"Applied patch to <path> (N additions, M deletions)"` or error message.
- Flags: `-u` unified, `-F3` fuzz tolerance, `-l` ignore whitespace, `-N` forward only, `--posix` strict conformance.
- Use `ToolRuntime::run()` wrapper for cancellation checkpoints around filesystem mutations.

Out of scope:
- No custom patch parser or Codex DSL.
- No file creation via patch.
- No fuzzy matching beyond GNU patch flags.
- No read-before-edit enforcement.
- No standalone `PatchRunner` utility class — the patch subprocess logic lives in `EditFileTool`.

## Acceptance criteria
- `edit` tool is discoverable through registry-backed Symfony Toolbox metadata via `HatfieldToolProviderInterface`.
- Bad patches leave the original file unchanged and return actionable patch stderr/stdout.
- Good unified diff patches update the target file atomically and report additions/deletions.
- Whitespace-tolerant match works via `-l` flag.
- Multi-hunk patches apply correctly.
- Tool rejects missing target files with a clear message directing use of `write` for new files.
- Cancellation during process execution uses `ToolRuntime::runCancellableProcess()` and leaves original file untouched.
- Temp files are cleaned up on all paths (success, failure, cancellation).
- Focused tests pass with Castor/PHPUnit.
- `castor deptrac` passes.

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
- Created: 2026-05-17T04:42:49.755Z
- Updated: 2026-05-29 — merged TOOLS-05 scope into this task; updated dependencies to reflect actual TOOLS-00 shipped surface.
