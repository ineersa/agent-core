# EDITOR-07 Prompt history navigation and session persistence

## Goal
Plan: .pi/plans/editor_rollout_plan.md

MVP: no.

Scope:
- Add PromptHistory abstraction and wire submitted prompts into session-aware history.
- Implement MVP history behavior: empty editor + Up/Down cycles submitted prompts.
- Exit history navigation mode once user types normal input.
- Persist/load prompt history alongside session data according to docs/session-storage.md.

Exclusions:
- No visual-line-aware history navigation within multiline entries yet.
- No global cross-session history unless explicitly designed later.
- No completion or command palette history search.

Dependencies: EDITOR-05.
Parallelizable with: EDITOR-06 and EDITOR-08, but avoid concurrent session storage edits.

## Acceptance criteria
- Submitted prompts are appended to session prompt history.
- Empty editor Up/Down recalls previous/next prompt.
- Typing normal input exits history navigation mode.
- History survives session resume when session storage is available.
- Tests cover in-memory navigation and persistence/reload behavior.
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
- Created: 2026-05-18T00:15:49.260Z
