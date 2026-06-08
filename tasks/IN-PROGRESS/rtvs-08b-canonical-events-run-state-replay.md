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
Status: IN-PROGRESS
Branch: task/rtvs-08b-canonical-events-run-state-replay
Worktree: /home/ineersa/projects/agent-core-worktrees/rtvs-08b-canonical-events-run-state-replay
Fork run: h3dua8nfckje
PR URL:
PR Status:
Started: 2026-06-08T00:15:30.019Z
Completed:

## Work log
- Created: 2026-06-07T16:26:19.504Z

## Task workflow update - 2026-06-08T00:15:30.019Z
- Moved TODO → IN-PROGRESS.
- Created branch task/rtvs-08b-canonical-events-run-state-replay.
- Created worktree /home/ineersa/projects/agent-core-worktrees/rtvs-08b-canonical-events-run-state-replay.
- Copied vendor directory into /home/ineersa/projects/agent-core-worktrees/rtvs-08b-canonical-events-run-state-replay.
- Copied .vera index into /home/ineersa/projects/agent-core-worktrees/rtvs-08b-canonical-events-run-state-replay.
- Validation: Pre-start task context read: tasks/TODO/rtvs-08b-canonical-events-run-state-replay.md; Referenced docs/tasks read: docs/session-storage.md, tasks/TODO/rtvs-08-session-replay-runtime-events.md, tasks/DONE/rtvs-08a-remove-transcript-jsonl.md; Integration checkout status before move: clean and in sync with origin/main
- Summary: Starting RTVS-08B task-start phase. Main agent remains orchestrator; implementation will be delegated to a fork after scout context gathering. RTVS-08A is DONE/merged, so this task starts from main with transcript.jsonl removed and TUI transcript replay already backed by events.jsonl.

## Task workflow update - 2026-06-08T00:22:55.024Z
- Validation: Scouts only; no code validation run during task-start recon.; Scout output saved at /home/ineersa/.pi/agent/tmp/2026-06--987b255c.txt
- Summary: Task-start scout recon complete. Three scouts mapped current post-RTVS-08A state: events.jsonl now has initial prompt and steer/follow_up messages, but still lacks full tool result message payloads and full HITL answer AgentMessage payloads for RunState.messages replay; ReplayService only rebuilds PromptState, not RunState; resume currently falls back to RunState::queued() if state.json is missing and has no event-rebuild path. Best seam identified: add RunState replay/reducer service in AgentCore, integrate before message handling/resume so missing or stale state.json is rebuilt from canonical events, and update event payloads for tool result/HITL answer message content.
- Scout #1: AgentCore event emission coverage — critical gaps are tool result message content missing from tool events and human_response command event missing full AgentMessage; StartRun and steer/follow_up message coverage exists post-RTVS-08A; llm_step_completed has assistant_message; pendingToolCalls derivable from tool_execution_start/end.
- Scout #2: replay/storage/resume — ReplayService builds PromptState only; RunMessageProcessor uses runStore->get() ?? RunState::queued(), so missing state.json on existing runs is incorrect; proposed RunStateReplayService using EventStoreInterface + RunStoreInterface, detecting sequence gaps and rebuilding/persisting checkpoint before Continue/handler processing.
- Scout #3: tests/docs — add RunState replay tests for initial+assistant, follow_up/steer, tool result, HITL/cancel/error, non-contiguous history, missing/stale state.json; update docs/session-storage.md stale claims that state.json is required/not rebuildable.

## Task workflow update - 2026-06-08T00:37:25.500Z
- Recorded fork run: h3dua8nfckje
- Validation: Fork reported: castor test --filter=RunStateReplayServiceTest PASS (16 tests, 67 assertions); Fork reported: focused handler/replay tests PASS (38 tests across handler classes); Fork reported: castor test PASS (2001 tests, 5866 assertions); Fork reported: castor test:tui PASS (5 tests, 18 assertions); Fork reported: castor test:controller PASS (1 test, 7 assertions); Fork reported: castor test:llm-real PASS (4 tests, 29 assertions); Fork reported: castor deptrac PASS (0 violations, 0 errors); Fork reported: castor phpstan PASS (0 errors, 0 file_errors); Fork reported: castor cs-check PASS (0 files fixed); Parent verification: git status clean at commit 30757408; git diff --stat origin/main...HEAD shows 8 files changed, 1272 insertions, 9 deletions
- Summary: Implementation fork completed RTVS-08B and committed 30757408 on branch task/rtvs-08b-canonical-events-run-state-replay. Verified worktree clean and diff matches expected scope: 8 files changed (4 new, 4 modified), including new RunStateReplayService/Result/Exception, RunStateReplayServiceTest, event payload additions for human_response/tool message_end, RunMessageProcessor stale/missing state replay integration, and docs/session-storage.md updates. events.jsonl now carries replay-critical tool-result and HITL message payloads; RunState can be deterministically rebuilt from canonical events; missing/stale state.json is recovered before processing.
- task-start fork h3dua8nfckje: implemented canonical event completeness + deterministic RunState replay. New service reduces canonical RunEvent stream into RunState, throws on non-contiguous history, treats empty event stream as no-events, and integrates in RunMessageProcessor before handler execution when state is missing/stale. Tool result AgentMessage payloads are now included in tool message_end events; human_response applied events now include full serialized AgentMessage. Full PR/review/gate intentionally not started per task-start workflow.

## Task workflow update - 2026-06-08T00:59:55.395Z
- Validation: Reviewer verdict at 30757408: APPROVE WITH SUGGESTIONS; Reviewer found no critical issues; tests previously fork-reported passing remain recorded separately
- Summary: Reviewer subagent completed first review of RTVS-08B implementation at commit 30757408. Verdict: APPROVE WITH SUGGESTIONS. No critical issues; core replay design judged sound. Actionable suggestions: fix reducer semantic mismatch by resetting pendingToolCalls at start of applyLlmStepCompleted; rebase/merge latest origin/main because output-cap changes landed after branch point; remove duplicate/incomplete docblocks in RunStateReplayService; optional cleanup/nice-to-haves around by-ref accumulator pattern, public replay() docs, and optional constructor injection.
- reviewer: key issue RunStateReplayService::applyLlmStepCompleted appends pending tool calls but real LlmStepResultHandler resets pendingToolCalls before adding current step tool calls; suggested reset accumulator to match handler semantics.
- reviewer: branch divergence warning — branch should be rebased/merged onto latest origin/main before PR to preserve output-cap code and avoid conflicts.
- reviewer: cleanup suggestions — remove duplicate param-only docblocks in RunStateReplayService around applyLlmStepCompleted/applyMessageEnd/applyToolExecutionStart; consider documenting public replay() contiguity precondition and revisiting nullable RunStateReplayService injection later.
