# COMP-04 Compaction hooks, observability, and TUI event projection

## Goal
Plan reference: `.pi/plans/context-compaction-implementation-plan.md` sections 7, 14.2, 16, 18, 19.3, 21 Phase 1.

Scope:
- Add before-compaction hook contracts for cancel/replacement summary/additional instructions/metadata.
- Ensure after-compaction observation works through committed events and existing after-turn/event hooks.
- Project `context_compaction_started`, `context_compacted`, and `context_compaction_failed` into runtime/TUI-visible events/status as needed.
- Add structured logging for compaction lifecycle without raw prompts or full session content.

Execution order: depends on COMP-02. Can run in parallel with COMP-03 after COMP-02 lands, but coordinate on runtime/TUI event names.

## Acceptance criteria
- Before-compaction hooks can cancel compaction with a reason, provide replacement summary, append instructions, and attach metadata.
- Replacement summary path skips the LLM call but still emits the mandatory lifecycle events and compacted checkpoint.
- Existing after-turn/event hooks can observe committed `context_compacted` events with compacted state.
- Runtime/TUI projection surfaces start/success/failure lifecycle without leaking raw prompts or full summaries by default.
- Structured logs include correlation fields such as `run_id`, `session_id`, `component`, and `event_type` and do not include raw prompts/tool output/full session content.
- Relevant Castor tests pass; final PR must pass `castor check` if runtime/TUI projection is touched.

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
- Created: 2026-06-08T15:40:15.290Z
