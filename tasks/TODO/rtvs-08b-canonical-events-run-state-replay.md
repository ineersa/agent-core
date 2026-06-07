# RTVS-08B Canonical events and RunState replay

## Goal
## Goal
Make `.hatfield/sessions/<id>/events.jsonl` the complete canonical run log for execution state, and add the ability to rebuild AgentCore `RunState` from canonical events.

## Context
User direction: `events.jsonl` should be ALL IN ONE — the complete log of a run. `state.json` may remain as a hot checkpoint/projection for now, but it should be rebuildable and disposable rather than an independent source of truth. This unblocks future DB-backed small state/projection storage without moving today’s large duplicated `messages` blob as-is.

Existing related tasks:
- `rtvs-08-session-replay-runtime-events.md` is stale: it references deleted `runtime-events.jsonl` and should be implemented against canonical `events.jsonl`.
- `rtvs-08a-remove-transcript-jsonl.md` removes transcript persistence and rebuilds TUI transcript from `events.jsonl`.

Important current gaps from scout findings:
- `events.jsonl` currently cannot fully reconstruct user messages/follow-ups/steers because there is no replayable `user.message_submitted`/message-mutation event coverage for normal prompts.
- Tool result message content and some execution state transitions may still live only in `RunState.messages`.
- Existing `ReplayService` rebuilds a hot prompt-state snapshot, not the full current `RunState` required to continue execution.

Recommended sequencing:
1. Complete this task first or in parallel only with careful coordination, because RTVS-08A depends on transcript-critical user events being present.
2. Then update RTVS-08/RTVS-08A to replay TUI transcript from canonical `events.jsonl` and stop using `transcript.jsonl`.

## Out of scope
- Moving state storage to the database.
- Moving canonical event storage from JSONL to the database.
- Fork/branch session trees.
- Compatibility readers for old incomplete event logs unless explicitly requested.

## Acceptance criteria
- Canonical `events.jsonl` contains replayable events for every prompt-context mutation needed to rebuild `RunState.messages`: initial prompt/context, follow-up messages, steers, accepted HITL answers, assistant messages, tool-call/result messages, and relevant error/cancellation/waiting-human transitions.
- A deterministic, idempotent RunState replay/reducer service can rebuild the current `RunState` from canonical events for a run, including `status`, `turnNo`, `lastSeq`, `activeStepId`, pending tool-call/waiting-human state, errors/retryability, and `messages`.
- Replay records/returns the max applied event seq and detects non-contiguous or incompatible event history with an explicit diagnostic instead of silently producing partial state.
- Resume/continue can recover when `state.json` is missing or stale by rebuilding from `events.jsonl` before advancing the run; `state.json` remains a checkpoint/projection, not a required source of truth.
- Tests cover rebuild equivalence for at least: initial prompt + assistant response, follow-up or steer, one tool result path, and one HITL/cancellation/error path.
- Docs update `docs/session-storage.md` and related task/plan references to state that `events.jsonl` is canonical and `state.json` is a rebuildable hot checkpoint/projection.
- Validation uses Castor per project rules; runtime/Messenger changes require full `castor check` before CODE-REVIEW.

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
- Created: 2026-06-07T16:26:19.504Z
