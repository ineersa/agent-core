# RTVS-11 Runtime follow-ups and RTVS replan

## Goal
Context after RTVS-07 merge:

RTVS-07 is merged and real `castor test:llm-real` passed for two-turn TUI/LLM flow. However several architectural/runtime follow-ups were discovered and should be handled before RTVS-08 replay work.

Findings to preserve:

1. `SubmitListener` currently decides follow_up vs steer using `$screen->registry()->getWorkingMessage() !== ''`. This is likely only a presentation heuristic, not an authoritative run activity signal. Need a proper runtime/TUI state indicator for whether the agent is actively running/processing vs idle/completed.
2. Command semantics clarified by user:
   - `follow_up` = normal next user message when LLM/run is idle/completed; should be sent as a normal user message for the next turn.
   - `steer` = steering/injected message while LLM/tool loop is running; should queue and apply at next safe boundary between LLM/tool turns.
3. `after_turn_commit_hook_failed` warning fires on every commit with `events must be a list of AfterTurnCommitEventSummary.` It is non-blocking in RTVS-07 but should be fixed or deliberately removed/noised down.
4. TUI/LLM execution is still synchronous in the in-process path. During a second submit, rendering can block until the LLM call returns. This is acceptable short-term but should be documented/considered before richer streaming UX.
5. The product-level TUI smoke tests now cover a lot of RTVS-09/10 intent. RTVS-09 and RTVS-10 should be reviewed/re-scoped instead of blindly implemented. RTVS-08 replay should wait until these follow-ups are resolved so replay doesn't codify unstable runtime semantics.

Related RTVS status guidance:
- RTVS-09 deterministic vertical slice tests: likely mostly covered by `TuiAgentSmokeTest` + real `castor test:llm-real`; reassess and either close, shrink, or convert to focused regression coverage.
- RTVS-10 manual smoke/docs: largely covered by AGENTS.md validation rule + real smoke workflow; reassess docs gaps only.
- RTVS-08 session replay: defer until follow_up/steer state semantics and hook warning are settled.

## Acceptance criteria
- Replace `getWorkingMessage()`-based follow_up/steer decision with an authoritative run activity signal or document why the current heuristic is acceptable short-term.
- Validate follow_up vs steer semantics with a real product-level flow: idle second message uses follow_up; active/running submit uses steer or has an explicit tested behavior.
- Fix or intentionally remove/no-op the universal `after_turn_commit_hook_failed` warning; logs should not emit this warning on every normal commit.
- Reassess RTVS-08/09/10 task scopes and update task files accordingly: 09/10 may be closed/shrunk if already covered; 08 remains deferred until follow-ups are done.
- Run and report required product-level validation (`castor test:llm-real` or `castor run:agent-test`) plus normal quality gates for any runtime changes.

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
- Created: 2026-05-21T22:28:39.502Z
