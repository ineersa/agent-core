# SESSION-02 TUI session switch and reset lifecycle foundation

## Goal
## Goal
Create the TUI/runtime foundation needed to switch the running TUI between sessions without restarting the whole terminal process.

## Context
Current architecture assumes one TUI process owns one session for its lifetime. Scouts found the hard reset points:
- `ChatScreen` captures session id in the footer provider at construction time.
- `TranscriptProjector` is a stateful DI singleton and must be reset between sessions.
- `QuestionCoordinator` is stateful and has no public reset/clear API.
- `TuiSessionState` contains many mutable per-session fields (`handle`, transcript, `lastSeq`, usage, footer state, poll errors, activity, etc.).
- `JsonlProcessAgentSessionClient` is session/run scoped and process transport must not leak old-session queues/events.

This task should introduce a reusable session switch/reset service or equivalent orchestration seam. Later commands (`/resume`, `/new`) should use this instead of duplicating reset logic.

## Dependencies
- Best after RTVS-08 final resume integration, because switching to an existing session relies on canonical `events.jsonl` transcript replay and rebuild/checkpoint behavior.
- May be implemented before command UI if covered by tests through direct service calls.

## Out of scope
- Slash command registration and picker UI.
- Session name/list API (SESSION-01).
- Tree/branch navigation.

## Acceptance criteria
- A single TUI-facing lifecycle abstraction can switch to an existing session id or reset to a fresh pending session without rebuilding the whole CLI process.
- Switching sessions resets all per-session mutable state: transcript, `lastSeq`, poll timing/errors, activity, usage, handle/request, footer model/reasoning/context, and session start timing.
- Switching sessions resets `TranscriptProjector` and prevents projected blocks from the old session leaking into the new session.
- Open/pending question overlays and `QuestionCoordinator` state are closed/reset or switching is rejected with a clear diagnostic; no stale HITL question remains bound to the old session.
- The footer/header/session display updates to the new session id/name; `ChatScreen` no longer bakes stale session id text for the lifetime of the process.
- Process and in-process transports do not leak events from the previous run after a switch; dedup cursor is initialized from replayed history for resumed sessions.
- Switching behavior is tested for at least fresh -> resumed and resumed -> fresh transitions with no duplicate transcript blocks.
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
- Created: 2026-06-07T20:45:22.373Z
