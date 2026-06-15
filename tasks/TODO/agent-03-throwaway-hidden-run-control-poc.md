# AGENT-03 Throwaway hidden-run and agent-control POC

## Goal
Run a deliberately disposable spike after AGENT-01/AGENT-02 to validate the hard architecture before production implementation. This is not an MVP and must not become a fallback/compatibility path.

Context:
- Depends on AGENT-01 and AGENT-02, unless the user explicitly chooses to spike with one hardcoded definition first.
- Reference plan: `.pi/plans/agents-subagents-implementation-plan.md`, especially Stage -1.
- Goal is to learn whether hidden child runs + parent registry + selected child event replay can work cleanly through the runtime/TUI boundary.
- Production implementation should be rewritten based on findings; do not preserve messy spike seams.

POC questions to answer:
- Can a parent run start/supervise a hidden child run without exposing it in normal session listing?
- Can a parent-scoped file registry track `agent_run_id`, status, artifact id, and attention/completion state?
- Can `/agents` or a temporary agent-control view select a child and rebuild from that child session's own `events.jsonl`?
- Can live selected-child updates be streamed/polled as normal runtime events with `runId = child_run_id` plus `parent_run_id` metadata, without mirroring every child event into the parent session?
- What TUI projection/state problems appear before production design is locked in?

Spike constraints:
- Hardcode one agent if needed, likely `scout`.
- Skip MCP policy, broad builtins, parallel execution, foreground `WaitingAgent`, and polished docs.
- Skip compatibility/fallback layers.
- Keep all code clearly disposable.
- Preferred final deliverable is a findings document/handoff; if spike code is not deleted before review, the task is not production-ready.

## Acceptance criteria
- A concise findings document or task handoff records what worked, what failed, and the recommended production structure changes.
- The spike demonstrates or falsifies hidden child run creation, parent registry tracking, selected child replay, and live selected-child update routing.
- The parent session does not duplicate full child events; child session events remain the detailed source of truth.
- Normal session listing exclusion for hidden child runs is evaluated and documented, even if implemented crudely in the spike.
- No compatibility/fallback layers are introduced.
- Do not move this task to CODE-REVIEW/DONE with retained TUI/runtime code unless a real TmuxHarness TUI E2E proof exists and passes `castor test:tui`; preferred completion is deleting spike code and committing only findings.
- Validation and observations use Castor commands only where tests are run; record blockers if tmux or llama.cpp:9052 prerequisites are unavailable.

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
- Created: 2026-06-15T22:52:30.239Z
