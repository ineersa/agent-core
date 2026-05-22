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
Status: DONE
Branch: task/rtvs-11-runtime-followups-and-rtvs-replan
Worktree: /home/ineersa/projects/agent-core-worktrees/rtvs-11-runtime-followups-and-rtvs-replan
Fork run: 5n1e98yudyo1
PR URL: https://github.com/ineersa/agent-core/pull/36
PR Status: merged
Started: 2026-05-21T22:57:51.608Z
Completed: 2026-05-22T00:32:16.722Z

## Work log
- Created: 2026-05-21T22:28:39.502Z

## Task workflow update - 2026-05-21T22:57:51.608Z
- Moved TODO → IN-PROGRESS.
- Created branch task/rtvs-11-runtime-followups-and-rtvs-replan.
- Created worktree /home/ineersa/projects/agent-core-worktrees/rtvs-11-runtime-followups-and-rtvs-replan.
- Copied vendor directory into /home/ineersa/projects/agent-core-worktrees/rtvs-11-runtime-followups-and-rtvs-replan.
- Copied .vera index into /home/ineersa/projects/agent-core-worktrees/rtvs-11-runtime-followups-and-rtvs-replan.
- Summary: Starting RTVS-11 implementation after design decision: use explicit TUI/runtime run activity state for follow_up vs steer; fix after_turn_commit warning even if currently no production subscribers; remove RTVS-09/10 per user decision; keep RTVS-08 deferred. Async/responsiveness direction is separate headless control process + worker runtime plan/spike so TUI stays responsive and command acks are low-latency.

## Task workflow update - 2026-05-21T23:00:02.437Z
- Recorded fork run: 5n1e98yudyo1
- Summary: Launched implementation fork in worktree /home/ineersa/projects/agent-core-worktrees/rtvs-11-runtime-followups-and-rtvs-replan. Scope: explicit TUI run activity state; replace working-message follow_up/steer heuristic; fix after_turn_commit warning via production serializer or equivalent; remove RTVS-09/10 and update RTVS-08; prepare async/process-runtime plan around responsive headless control process + worker and steer/cancel latency.

## Task workflow update - 2026-05-21T23:09:45.397Z
- Validation: PASS: castor test --filter="AfterTurnCommitSerializerRegression|HookDispatcherContract|RuntimeEventMapper|RuntimeEventType|JsonlCodec|SessionRunStore|TranscriptProjector" (165 tests, 715 assertions); PASS: castor deptrac (0 violations, 0 errors); PASS: castor phpstan --path=src/Tui; PASS: castor phpstan --path=src/AgentCore/Application/Handler; PASS: castor phpstan --path=src/AgentCore/Domain/Extension; PASS: castor phpstan --path=src/CodingAgent/Runtime/Contract; PASS: castor cs-check; BLOCKED/FAIL expected pre-existing: castor test:tui fails before rendering because container boot errors: Service id "Monolog\Logger" looks like a FQCN but no corresponding class or interface exists; matches fork-reported pre-existing Monolog vendor issue.
- Summary: Fork run 5n1e98yudyo1 completed and parent reviewed/amended in worktree. Final commit is 35612d07 feat: RTVS-11 runtime follow-ups and RTVS replan (amended from fork commit edf038b0). Parent amendment sets activity=Starting immediately after first run start and broadens RuntimeEventPoller activity transitions for stream/tool/HITL/cancellation/failure runtime events. Product-level castor test:tui attempted but blocked by pre-existing missing Monolog\Logger/Monolog\Level vendor issue during container boot.

## Task workflow update - 2026-05-21T23:13:10.308Z
- Validation: PASS after castor install: Monolog vendor restored in worktree for product validation; PASS: castor test --filter="AfterTurnCommitSerializerRegression|HookDispatcherContract|RuntimeEventMapper|RuntimeEventType|JsonlCodec|SessionRunStore|TranscriptProjector" (165 tests, 715 assertions); PASS: castor deptrac (0 violations, 0 errors); PASS: castor phpstan --path=src/Tui; PASS: castor phpstan --path=src/AgentCore/Application/Handler; PASS: castor phpstan --path=src/AgentCore/Domain/Extension; PASS: castor phpstan --path=src/CodingAgent/Runtime/Contract; PASS: castor cs-check; PASS product/manual: castor run:agent-test launched tmux session %52; sent `Say exactly: fork-validation`; visible transcript showed user prompt and assistant `fork-validation`; artifacts in .hatfield/sessions/6615bb610ff6; PARTIAL/known brittle: castor test:tui ran real flows; 2/5 passed, multi-turn produced `one` and `two` but failed because first prompt scrolled out of visible capture; startup snapshot tests timed out waiting for logo because prompt response output scrolled startup logo out before assertion.
- Summary: Parent continued validation after installing missing vendor dependency with castor install. Fixed container-compile issue in serializer.yaml by using Symfony 8 MetadataAwareNameConverter constructor arg $metadataFactory (not $classMetadataFactory). Final amended commit is now 3e579fa1. Product-level manual workflow through castor run:agent-test passed: TUI launched in tmux, accepted a follow_up prompt, rendered visible user block and assistant response `fork-validation`, and session artifacts were written under .hatfield/sessions/6615bb610ff6. castor test:tui now starts and exercises real flows, but remains not fully green because existing snapshot/e2e assertions are brittle with verbose real-model output: startup prompt response scrolls the Hatfield logo out before the wait, and the multi-turn first prompt scrolls out of the visible pane; the actual two-turn flow produced both responses.

## Task workflow update - 2026-05-22T00:28:57.884Z
- Moved IN-PROGRESS → CODE-REVIEW.
- Pushed task/rtvs-11-runtime-followups-and-rtvs-replan to origin.
- branch 'task/rtvs-11-runtime-followups-and-rtvs-replan' set up to track 'origin/task/rtvs-11-runtime-followups-and-rtvs-replan'.
- Created PR: https://github.com/ineersa/agent-core/pull/36
- Validation: PASS: castor test --filter="AfterTurnCommitSerializerRegression|HookDispatcherContract|RuntimeEventMapper|RuntimeEventType|JsonlCodec|SessionRunStore|TranscriptProjector" (165 tests, 715 assertions); PASS: castor deptrac (0 violations, 0 errors); PASS: castor phpstan --path=src/Tui; PASS: castor phpstan --path=src/AgentCore/Application/Handler; PASS: castor phpstan --path=src/AgentCore/Domain/Extension; PASS: castor phpstan --path=src/CodingAgent/Runtime/Contract; PASS: castor cs-check; PASS product/manual: castor run:agent-test launched tmux, sent `Say exactly: fork-validation`, visible transcript showed user prompt and assistant `fork-validation`, artifacts in .hatfield/sessions/6615bb610ff6; PARTIAL/known brittle: castor test:tui starts/runs but not fully green due existing snapshot/e2e visible-pane brittleness with verbose real model output; actual multi-turn flow produced both responses
- Summary: Ready for review. Final task branch commit is 7b415b2b (amended after 3e579fa1 to include the requested .pi/plans/async-headless-messenger-plan.md). Implementation covers explicit TUI run activity state for follow_up vs steer, serializer fix for after_turn_commit_hook_failed, RTVS-08/09/10 replan/removal, and async/headless runtime planning.

## Task workflow update - 2026-05-22T00:32:16.722Z
- Moved CODE-REVIEW → DONE.
- Merged task/rtvs-11-runtime-followups-and-rtvs-replan into integration checkout.
- Merge made by the 'ort' strategy.
 .pi/plans/async-headless-messenger-plan.md         | 484 +++++++++++++++++++++
 config/packages/serializer.yaml                    |  44 ++
 docs/async-process-runtime-plan.md                 | 143 ++++++
 src/CodingAgent/Runtime/Contract/UserCommand.php   |   2 +-
 src/Tui/Listener/SubmitListener.php                |  30 +-
 src/Tui/Runtime/RunActivityStateEnum.php           |  66 +++
 src/Tui/Runtime/RuntimeEventPoller.php             |  61 +++
 src/Tui/Runtime/TuiSessionState.php                |   9 +
 .../TODO/rtvs-08-session-replay-runtime-events.md  |  20 +-
 .../rtvs-09-deterministic-vertical-slice-tests.md  |  38 --
 tasks/TODO/rtvs-10-manual-smoke-docs.md            |  38 --
 .../rtvs-11-runtime-followups-and-rtvs-replan.md   |  78 +++-
 .../AfterTurnCommitSerializerRegressionTest.php    | 158 +++++++
 13 files changed, 1061 insertions(+), 110 deletions(-)
 create mode 100644 .pi/plans/async-headless-messenger-plan.md
 create mode 100644 docs/async-process-runtime-plan.md
 create mode 100644 src/Tui/Runtime/RunActivityStateEnum.php
 delete mode 100644 tasks/TODO/rtvs-09-deterministic-vertical-slice-tests.md
 delete mode 100644 tasks/TODO/rtvs-10-manual-smoke-docs.md
 create mode 100644 tests/AgentCore/Application/Handler/AfterTurnCommitSerializerRegressionTest.php
- Removed worktree /home/ineersa/projects/agent-core-worktrees/rtvs-11-runtime-followups-and-rtvs-replan.
- Pulled integration checkout: Merge made by the 'ort' strategy..
- Summary: PR #36 was merged on GitHub; marking RTVS-11 done. The local untracked copy of .pi/plans/async-headless-messenger-plan.md was moved to /tmp/agent-core-backups/async-headless-messenger-plan.md.main-untracked before merging because the merged PR now provides the tracked file.
