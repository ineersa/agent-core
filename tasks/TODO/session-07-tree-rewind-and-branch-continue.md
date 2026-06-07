# SESSION-07 /tree rewind to turn and continue on a branch

## Goal
## Goal
Make `/tree` actionable: allow the user to select a prior turn in the current session, move the active leaf there, and continue from that point as a new branch.

## Desired UX
- `/tree` opens the tree picker.
- Selecting a turn moves the active session context back to that turn/branch.
- If the selected item is a user turn, the user prompt may be restored into the editor for editing/re-submission where appropriate; otherwise continuation starts from the selected branch state.
- New messages after selection append to a branch, preserving abandoned future history as sibling branches rather than deleting it.

## Dependencies
- SESSION-05 turn tree model and replay anchors.
- SESSION-06 read-only tree picker.
- RTVS-08B-style RunState replay must support rebuilding state for the selected branch/leaf.

## Out of scope
- LLM-generated branch summaries unless explicitly added in a later task.
- Exporting/forking a branch into a separate session.
- Destructive truncation of session history.

## Acceptance criteria
- Selecting a prior turn records an append-only canonical tree navigation/leaf-change event or equivalent metadata; `events.jsonl` history is not truncated.
- The current TUI transcript and AgentCore prompt/run state are rebuilt to match the selected branch/leaf.
- Subsequent user messages continue from the selected turn as a new branch with correct parent/leaf metadata.
- Abandoned future turns remain visible in `/tree` as sibling/old branch history.
- The dedup cursor, activity state, pending HITL/tool/cancellation/error state, and footer/session display remain coherent after branch selection.
- If selecting a user turn restores editable text, the behavior is deterministic and documented; if not supported, selection behavior is clearly defined and tested.
- Tests cover selecting an earlier turn, continuing with a new message, preserving old branch history, resume after tree navigation, and replaying the active branch only.
- Docs describe `/tree` branch/rewind semantics and limitations.
- Validation uses Castor per project rules; runtime/TUI/Messenger changes require full `castor check` before CODE-REVIEW.

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
- Created: 2026-06-07T20:46:28.593Z
