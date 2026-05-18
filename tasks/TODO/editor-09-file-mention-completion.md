# EDITOR-09 File mention completion and resolution

## Goal
Plan: .pi/plans/editor_rollout_plan.md

MVP: no.

Scope:
- Add FileMentionCompletionProvider for @ tokens at token boundaries.
- Search CWD using fd when available, respecting .gitignore; fallback to PHP filesystem traversal.
- Insert quoted paths when necessary, e.g. @"path with spaces/file.php".
- Keep file mention completion in editor/completion layer; runtime context expansion can remain a later integration point if not already available.

Exclusions:
- No LSP/fuzzy semantic indexing.
- No image/file attachment paste.
- No runtime prompt context injection beyond path insertion unless existing APIs support it.

Dependencies: EDITOR-08.
Parallelizable with: EDITOR-10.

## Acceptance criteria
- @ token boundary detection is covered by tests.
- Provider returns reasonable path suggestions from the project CWD.
- fd and PHP fallback paths are both testable or abstracted behind a service for deterministic tests.
- Accepted suggestions insert correctly quoted paths when needed.
- castor deptrac passes.

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
- Created: 2026-05-18T00:16:03.500Z
