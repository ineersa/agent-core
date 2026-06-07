# SESSION-06 /tree read-only turn tree picker

## Goal
## Goal
Add the first `/tree` TUI command as a read-only visual picker for the current session's turn tree.

## Desired UX
- `/tree` opens a tree/list overlay near the bottom/editor area.
- It shows turns in the current session, the current leaf/head, branch structure, and useful labels/previews.
- Navigation keys follow existing picker/list patterns.
- This task is read-only: selecting a turn may close the picker or show details, but must not yet change execution state.

## Dependencies
- SESSION-05 turn tree read model.
- SESSION-03/04 picker patterns may be reused but are not conceptually required.

## Out of scope
- Rewinding/branching/continuing from a selected turn.
- Branch summaries/compaction.
- Cross-session tree/fork extraction.

## Acceptance criteria
- `/tree` is registered with help/usage metadata.
- `/tree` builds its data from the canonical turn tree read model for the current session, not from transient TUI transcript state alone.
- The picker displays enough information to distinguish turns: turn/anchor id, role or kind, prompt/assistant preview where safe, branch depth/indentation, timestamp if available, and current leaf marker.
- Keyboard navigation and cancel behavior match existing `SelectListWidget`/picker conventions.
- Opening/closing the tree picker does not alter run state, transcript, current leaf, or editor text.
- Tests cover tree command registration, rendering data construction for linear and branched histories, and cancel/close behavior.
- Docs/help text document `/tree` as read-only in this phase.
- Validation uses Castor per project rules; TUI changes require full `castor check` before CODE-REVIEW.

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
- Created: 2026-06-07T20:46:14.207Z
