# TOOLS-01 Implement PathResolver helper for file tools

## Goal
Implement a small static path resolution helper for the toolbox rollout.

Plan source: `.pi/plans/toolbox-design-plan.md`.

Scope:
- Create `src/CodingAgent/Tool/PathResolver.php`.
- Provide static helper(s) used by read/write/edit/view_image tools.
- Resolve absolute paths as-is after normalization.
- Resolve relative paths against the active project cwd / current working directory used by CodingAgent tools.
- Expand `~` to the user's home directory.
- Normalize `.` and `..` path segments without requiring the target path to already exist (write may create new files).
- Keep this as an application-layer utility in `CodingAgent`; do not add dependencies from `AgentCore` or `Tui`.
- Add focused PHPUnit tests under `tests/CodingAgent/Tool/`.

Out of scope:
- No sandbox/allowlist enforcement.
- No tool implementation in this task.
- Do not implement ToolRegistry.

## Acceptance criteria
- `PathResolver` exists under `src/CodingAgent/Tool/` and is usable by tool classes without service wiring.
- Tests cover absolute paths, cwd-relative paths, `~` expansion, and `.`/`..` normalization for non-existing paths.
- No `AgentCore` or `Tui` dependency is introduced from `CodingAgent/Tool`.
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
- Created: 2026-05-17T04:42:04.933Z
