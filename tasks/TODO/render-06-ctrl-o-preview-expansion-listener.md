# RENDER-06: Ctrl+O preview expansion listener

## Goal
Part of .pi/plans/tui-rich-transcript-blocks-plan.md.

Order: depends on RENDER-01 for display state. Can start in parallel with RENDER-02/03/04 for listener plumbing. Full behavior validation depends on RENDER-04 and RENDER-05.

Scope:
- Add listener-owned `Ctrl+O` handling following existing TUI listener registrar style.
- Toggle only `TranscriptDisplayState::previewableBlocksExpanded`.
- Keep state session-only; do not persist to settings or session metadata.
- Ensure toggle invalidates/re-renders transcript appropriately.
- Confirm interaction with Symfony `EditorWidget` default `expand_tools => ctrl+o`; current vendor code declares but does not handle it, so validate real input path.

Parallelism: listener plumbing can be done after RENDER-01 while renderer tasks proceed. Final acceptance should run after RENDER-04/RENDER-05 so visible preview behavior exists.

## Acceptance criteria
- `Ctrl+O` toggles session-only preview expansion state.
- The toggle does not mutate Hatfield settings, session metadata, or canonical transcript events.
- User, assistant, thinking, system, error, progress, question, approval, cancelled, and tool-call blocks are unaffected.
- Normal tool result previews respond to the toggle once RENDER-04 is present.
- Diff previews respond to the toggle once RENDER-05 is present.
- Real TUI input path is validated with a product-level Castor workflow or included in final RENDER-07 validation.

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
- Created: 2026-05-22T19:09:14.549Z
