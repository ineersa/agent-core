# COMP-01 Compactor service, settings, prompt, and safe cut algorithm

## Goal
Plan reference: `.pi/plans/context-compaction-implementation-plan.md` sections 5, 6, 8, 9, 10, 11, 12, 19.1, 21 Phase 1.

Scope:
- Add compaction settings including `enabled`, `reserve_tokens`, `keep_recent_tokens`, `max_summary_tokens`, and configurable `model` string (`null` = session model, `provider/model` = override).
- Implement `SessionCompactor` preparation, safe cut-point selection, summarization prompt construction, injected summary message construction, and compact result DTOs.
- Keep algorithm and prompt construction unit-testable without real LLM.

Execution order: can run in parallel with COMP-00. Required before COMP-02 core pipeline.

## Acceptance criteria
- Settings defaults and docs include `compaction.model` with `null` session-model fallback and `provider/model` override semantics.
- `SessionCompactor::prepare()` returns no-op for short sessions and produces summarize/tail partitions for long sessions.
- Safe cut algorithm never leaves orphan tool results or splits assistant tool-call groups from tool results.
- Prompt and injected summary prefix match the plan, including custom instruction handling and prior-summary guidance.
- Unit tests cover preparation, cut points, prompt text, summary message metadata, and compact result DTOs.
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
- Created: 2026-06-08T15:39:41.691Z
