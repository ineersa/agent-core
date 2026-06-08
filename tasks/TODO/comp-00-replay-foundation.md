# COMP-00 Replay foundation for compaction checkpoints

## Goal
Plan reference: `.pi/plans/context-compaction-implementation-plan.md` sections 4.5, 15, 19.2, 21 Phase 0.

Scope:
- Fix or account for the existing replay mismatch where LLM completion events emit `assistant_message` but replay checks a different payload key.
- Add replay coverage proving canonical events reconstruct normal assistant messages.
- Add replay coverage for full message-list replacement semantics needed by `context_compacted.payload.messages` without implementing full compaction yet if the event type is not present.

Execution order: blocking prerequisite for COMP-02 and later runtime/resume work. Can run in parallel with COMP-01 because it mainly touches replay/tests.

## Acceptance criteria
- Replay from `events.jsonl` reconstructs normal assistant messages emitted by LLM step result events.
- Replay has tested full message-list replacement semantics for compaction checkpoint events or an equivalent documented fixture path.
- No backward-compatible dual-format shim is added unless unavoidable; tests/docs are updated to match the canonical event payload format.
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
- Created: 2026-06-08T15:39:26.589Z
