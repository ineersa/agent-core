# COMP-06 Auto-compaction with reserve-token policy

## Goal
Plan reference: `.pi/plans/context-compaction-implementation-plan.md` sections 3.1, 6, 17, 18, 19, 21 Phase 2.

Scope:
- Add automatic compaction using the reserve-token threshold policy: `estimatedContextTokens > contextWindow - reserveTokens`.
- Add after-turn and pre-LLM-call trigger points that schedule/run compaction safely.
- Add optional overflow recovery with a one-attempt guard.
- Reuse the Phase 1 compaction service, events, model resolution, replay behavior, and TUI feedback.

Execution order: Phase 2 task. Depends on successful completion of COMP-05/manual compaction.

## Acceptance criteria
- Auto-compaction triggers when estimated context exceeds `contextWindow - reserveTokens`; no percentage/min-turn/cooldown policy is introduced in this task.
- After-turn and pre-LLM-call checks schedule compaction without racing active turns or causing repeated loops.
- Provider context-overflow recovery attempts at most one compaction retry per overflow episode.
- Auto failures emit `context_compaction_failed`, preserve state, and do not retry repeatedly in the same turn.
- Manual `/compact` continues to work regardless of auto-compaction behavior.
- Real LLM/runtime validation covers long-session auto-compaction; final validation must include `castor check`.

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
- Created: 2026-06-08T15:40:36.542Z
