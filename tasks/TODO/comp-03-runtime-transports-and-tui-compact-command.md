# COMP-03 Runtime transports and TUI /compact command

## Goal
Plan reference: `.pi/plans/context-compaction-implementation-plan.md` sections 4.6, 4.7, 14, 16, 18, 19.3, 21 Phase 1, 22 resolved decisions.

Scope:
- Add first-class runtime `compact(runId, customInstructions?)` support.
- Implement both `InProcessAgentSessionClient` and `JsonlProcessAgentSessionClient` support in Phase 1.
- Add JSONL runtime command and HeadlessController routing.
- Add TUI `/compact [custom instructions]` slash command.
- Queue manual compaction during active runs until the next safe boundary.
- Display progress, success, and failure/error states in the TUI.

Execution order: depends on COMP-02. Can run in parallel with COMP-04 after COMP-02 lands.

## Acceptance criteria
- `AgentSessionClient::compact()` exists and is implemented for both in-process and process/JSONL runtimes.
- Headless JSONL protocol supports a `compact` command routed to the core runner.
- TUI slash command `/compact [custom instructions]` calls the runtime compact operation and passes custom instructions exactly.
- Active-run compaction requests queue until a safe boundary; Phase 1 must not reject active runs as the normal behavior.
- TUI shows `Compacting conversation...`, a success compaction block with token before/after, and user-visible errors for failed/empty summaries.
- Runtime/TUI tests cover in-process and JSONL paths where feasible.
- Because this touches TUI/runtime/LLM-visible flow, final validation must include `castor check`.

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
- Created: 2026-06-08T15:40:06.080Z
