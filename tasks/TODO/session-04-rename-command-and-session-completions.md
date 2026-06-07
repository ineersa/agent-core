# SESSION-04 /rename command and session argument completions

## Goal
## Goal
Add session rename UX and completion support for session-taking slash commands.

## Desired UX
- `/rename` opens a recent-session picker near the bottom/editor area.
- Selecting a session inserts a generated command into the editor, e.g. `/rename <session id> `, so the user can type the new name.
- `/rename <session id> <new name>` executes the rename.
- If no name is provided, show an error with a concrete command hint using the selected/provided real session id.
- For all completion flows, Tab inserts a completion into the editor while Enter executes the command/picker selection.

## Dependencies
- SESSION-01 for name metadata/listing.
- SESSION-03 for session picker command patterns.
- EDITOR-08 completion foundation and slash command completion before implementing Tab insertion/completion semantics.

## Out of scope
- Switching sessions (`/resume`, `/new`).
- Tree navigation.
- Renaming old compatibility session IDs not present in the DB.

## Acceptance criteria
- `/rename` is registered with help/usage metadata and supports `/rename <session id> <new name>` direct execution.
- `/rename` with no args opens a session picker that displays session id and current name/display fallback.
- Selecting from the picker inserts a concrete editable command into the prompt editor rather than executing immediately, leaving the cursor ready for the new name.
- Executing with no new name reports a clear error and includes an actionable example using the real session id, e.g. `/rename 42 My session name`.
- Successful rename updates session metadata and refreshes any visible picker/footer/session display that references that session.
- Session-id completions are available for `/resume` and `/rename` after EDITOR-08; Tab inserts selected completions into the editor and Enter executes.
- Tests cover direct rename, missing-name error hint, picker insertion, completion insertion, and metadata persistence.
- Docs/help text document `/rename`, `/resume <id>`, and completion behavior.
- Validation uses Castor per project rules; runtime/TUI changes require full `castor check` before CODE-REVIEW.

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
- Created: 2026-06-07T20:45:50.100Z
