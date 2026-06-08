# COMP-02 Core compaction pipeline, model invocation, and checkpoint events

## Goal
Plan reference: `.pi/plans/context-compaction-implementation-plan.md` sections 3, 7, 12, 13, 15, 18, 21 Phase 1.

Scope:
- Implement compaction as a core run/session operation that rewrites `RunState.messages` through the normal pipeline/commit path.
- Add mandatory events: `context_compaction_started`, `context_compacted`, `context_compaction_failed`.
- Invoke the summarization model with direct messages and no tools.
- Resolve compaction model from settings: session model by default, `provider/model` override when configured.
- Treat empty summaries as failures; preserve original messages and emit failure event.

Execution order: depends on COMP-00 and COMP-01. Required before COMP-03 and COMP-04.

## Acceptance criteria
- Core event enum/factory/commit flow supports all three mandatory compaction events.
- Successful compaction atomically replaces `RunState.messages` with `[summaryMessage, ...retainedTailMessages]` and persists `context_compacted.payload.messages` as the replay-authoritative snapshot.
- Summarization invocation uses explicit direct messages, no tools, no normal transcript stream deltas, and correct model resolution.
- Failure paths emit `context_compaction_failed` without mutating `RunState.messages`.
- Empty summary is always a failure, with reason suitable for TUI display.
- Replay/resume tests prove compacted state survives canonical event replay.
- Relevant Castor tests pass; final PR must pass `castor check`.

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
- Created: 2026-06-08T15:39:54.282Z
