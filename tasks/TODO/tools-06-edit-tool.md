# TOOLS-06 Implement edit tool using PatchRunner

## Goal
Implement the `edit` tool that applies standard unified diffs.

Plan source: `.pi/plans/toolbox-design-plan.md`.

Dependencies:
- Depends on TOOLS-R02 (Hatfield tool definition convention) and TOOLS-R03 (registry-backed Toolbox and allowlist wiring).
- Depends on TOOLS-00 (`ToolExecutionContextInterface`, `CancellationGuard`).
- Depends on TOOLS-01 (`PathResolver`).
- Depends on TOOLS-05 (`PatchRunner`).

Scope:
- Replace/complete `src/CodingAgent/Tool/EditFileTool.php`.
- Provide a Hatfield tool definition/provider for `edit` instead of relying on `#[AsTool]` metadata.
- Register `edit` as a permanent tool through the TOOLS-R02 built-in tool registrar/`ToolRegistryInterface`, including provider description, explicit JSON schema, prompt line, and concise guidelines. Execution flows through the TOOLS-R03 registry-backed Toolbox.
- Tool definition JSON schema should match `__invoke(string $path, string $patch)`.
- Resolve path with `PathResolver`.
- Check cancellation via `CancellationGuard` before mutating the target file.
- Require target file to exist; creation belongs to `write`.
- Use `PatchRunner` for dry-run + apply.
- After successful apply, compare old vs new content, calculate additions/deletions or use `diff -u` output for stats.
- Atomically replace target with temp output after successful apply.
- Return success text: `Applied patch to <path> (N additions, M deletions)`.
- Return patch stderr/stdout verbatim on failure so the model can correct and retry.
- Add focused tests.

Out of scope:
- No fuzzy custom matching beyond GNU patch flags in PatchRunner.
- No read-before-edit enforcement.
- No file creation via patch.

## Acceptance criteria
- `edit` tool is discoverable through registry-backed Symfony Toolbox metadata and present in `ToolRegistryInterface` permanent metadata.
- Bad patches leave the original file unchanged and return actionable patch output.
- Good unified diff patches update the target file and report additions/deletions.
- Cancellation before final file replacement uses the standard cancellation path and leaves the original file unchanged.
- Tool rejects missing target files with a clear message directing use of `write` for new files.
- Tests cover success, failure/no mutation, missing file, and multi-hunk patch.
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
- Created: 2026-05-17T04:42:49.755Z
