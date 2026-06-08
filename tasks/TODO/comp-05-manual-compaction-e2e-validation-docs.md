# COMP-05 Manual compaction E2E validation and documentation

## Goal
Plan reference: `.pi/plans/context-compaction-implementation-plan.md` sections 6, 14, 17, 18, 19, 20, 21 Phase 1.

Scope:
- Complete Phase 1 validation for manual `/compact` across unit, replay, runtime/TUI, process/JSONL, and real LLM smoke coverage.
- Update `docs/settings.md` and any user-facing docs for compaction settings and `/compact` behavior.
- Add real LLM smoke test using the project llama.cpp test setup.
- Verify the full manual compaction acceptance checklist.

Execution order: final Phase 1 integration task. Depends on COMP-02 and COMP-03; may also depend on COMP-04 if hooks are included in Phase 1 release.

## Acceptance criteria
- Docs explain `/compact [custom instructions]`, compaction settings, model override syntax, queueing behavior, events, and failure/error behavior.
- LLM smoke test proves long synthetic conversation compacts into shorter context, summary is non-empty, second compaction succeeds, and subsequent LLM call uses compacted context.
- Runtime/TUI E2E covers both in-process and process/JSONL compaction paths or records a blocker if a required prerequisite is unavailable.
- Phase 1 acceptance checklist in the plan is fully satisfied.
- `castor check` passes before PR/code review; if prerequisites are unavailable, task remains IN-PROGRESS with blockers recorded.

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
- Created: 2026-06-08T15:40:27.651Z
