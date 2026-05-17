# TOOLS-05 Implement PatchRunner utility around GNU patch

## Goal
Implement the reusable utility for applying unified diff patches safely.

Plan source: `.pi/plans/toolbox-design-plan.md`.

Dependencies:
- Depends on TOOLS-00 (`CancellableProcessRunner`).

Scope:
- Create `src/CodingAgent/Tool/PatchRunner.php`.
- Wrap GNU `patch` via `CancellableProcessRunner` rather than owning a custom Process loop.
- Use a two-pass approach:
  1. Validate: `patch -u -F3 -l -N --dry-run --posix -o /dev/null <target> <patch>`
  2. Apply: `patch -u -F3 -l -N -o <temp-out> <target> <patch>`
- Store patch input in a temp file; never invoke shell with interpolated patch contents.
- Use the shared cancellation/timeout behavior from `CancellableProcessRunner`.
- On dry-run failure, return/throw structured failure containing patch stdout/stderr and leave original file untouched.
- On apply success, produce temp output path/content and basic stats support (additions/deletions can be calculated by caller or utility).
- Clean up temp files where safe.
- Add focused tests with temporary files and small unified diffs.

Out of scope:
- No `edit` tool registration in this task.
- No file creation via patch.
- No custom patch parser or Codex DSL.

## Acceptance criteria
- Dry-run failure does not mutate the target file and exposes patch error output.
- Successful patch application writes to a temp output and does not modify target in place inside PatchRunner.
- Flags include `-u -F3 -l -N`; validation includes `--dry-run --posix`.
- Patch subprocess cancellation/timeout uses `CancellableProcessRunner` semantics.
- Tests cover successful patch, bad patch, whitespace-tolerant match (`-l`), and original-file-preserved-on-failure.
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
- Created: 2026-05-17T04:42:04.932Z
