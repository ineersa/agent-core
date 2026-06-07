# SESSION-01 Session catalog, display names, and listing API

## Goal
## Goal
Add first-class session catalog metadata so TUI session commands can show and manipulate sessions by `session id + session name`.

## Context
Scouts found `hatfield_session` currently has metadata (`cwd`, `prompt`, parent/root/model/reasoning, timestamps) but no `name`/`title` column and no `HatfieldSessionStore::listSessions()` API. Existing session IDs are DB auto-increment integers exposed as strings; `session_id === run_id` remains invariant.

This is a prerequisite for `/resume`, `/rename`, and session picker/completion UI.

## Scope
- Add nullable session display name metadata.
- Add DB-backed listing/query APIs for recent sessions.
- Provide a stable display DTO/array shape with id, name/display title, cwd, prompt preview, model/reasoning, created/updated timestamps.
- Update docs/tests.

## Out of scope
- Implementing slash commands.
- Switching active TUI sessions.
- Tree/branch navigation.
- Moving state/events storage.

## Acceptance criteria
- `hatfield_session` schema/entity supports an optional user-visible session name.
- `HatfieldSessionStore::loadMetadata()` and `updateMetadata()` include/update the session name without silently accepting unknown metadata keys beyond the documented shape.
- A DB-backed `listSessions()` style API returns recent sessions sorted by `updated_at` (or explicit requested sort) and includes `id + display name` data suitable for TUI pickers.
- Unnamed sessions have a deterministic display fallback (for example prompt preview or `Session <id>`) without mutating the DB name field.
- Tests cover create, load, update/rename metadata, listing order, and display fallback behavior.
- `docs/session-storage.md` documents session names and listing metadata.
- Validation uses Castor per project rules.

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
- Created: 2026-06-07T20:45:08.344Z
