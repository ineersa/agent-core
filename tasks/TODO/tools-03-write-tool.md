# TOOLS-03 Implement simple write tool

## Goal
Implement the simple `write` tool.

Plan source: `.pi/plans/toolbox-design-plan.md`.

Dependencies:
- Depends on TOOLS-01 (`PathResolver`).

Scope:
- Replace/complete `src/CodingAgent/Tool/WriteFileTool.php`.
- Register with `#[AsTool('write', description: 'Create or overwrite a file')]` or equivalent project convention.
- Schema should be derived from `__invoke(string $path, string $content)`.
- Resolve the path with `PathResolver`.
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
- `write` tool is discoverable through Symfony AI toolbox metadata.
- Tool creates missing parent directories and writes exact content.
- Tool overwrites existing files without requiring a prior read.
- Tool reports byte count and resolved path on success.
- Focused tests cover new file, nested directory creation, and overwrite.
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
