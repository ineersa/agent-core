# SESSION-03 /new and /resume session commands

## Goal
## Goal
Add interactive TUI commands for starting a fresh session and resuming/switching to an existing session.

## Desired UX
- `/resume` with no arguments opens a picker near the bottom/editor area listing recent sessions as `session id + session name` (plus useful secondary metadata if space permits).
- Selecting a session resumes/switches to it and reloads the TUI transcript/state from where it ended.
- `/resume <session id>` executes directly.
- `/new` clears the TUI into a fresh session state. Prefer lazy creation: a new DB/session directory is created on first submitted message, not merely by opening an empty draft, unless implementation constraints force a documented alternative.

## Dependencies
- SESSION-01 for session list/name metadata.
- SESSION-02 for safe in-process TUI session switching/reset.
- RTVS-08 final resume integration for reliable canonical-event replay and state recovery.

## Out of scope
- Tab completion insertion semantics (covered after EDITOR-08 by a later task).
- `/rename`.
- `/tree` and branch navigation.

## Acceptance criteria
- `/resume` is registered in the slash command registry with help/usage metadata and supports both picker mode and direct `/resume <session id>` execution.
- The resume picker uses the existing TUI list/picker patterns (`SelectListWidget`/overlay) and displays at least session id and session name/display fallback.
- Picker Enter executes the resume; Escape cancels without changing the active session.
- Direct `/resume <session id>` validates session existence and shows a clear transcript/status error for invalid IDs.
- A successful resume switches the running TUI to the target session, replays transcript/history without duplicate blocks/deltas, updates footer/session display, and continues polling from the correct dedup cursor.
- `/new` resets the running TUI to a fresh draft/session state and clears old transcript/activity without leaking old runtime events or questions.
- First message in a fresh `/new` state starts a new run/session correctly; empty `/new` does not create useless orphan session records if lazy creation is feasible.
- Tests cover direct resume, picker selection/cancel, invalid resume id, and `/new` reset/start behavior.
- Docs/help text document `/new`, `/resume`, and examples.
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
- Created: 2026-06-07T20:45:37.774Z
