# Backlog cleanup: triage and fix open GitHub issues one-by-one

## Goal
Umbrella task to clean up current open GitHub issues one at a time. Process required by user: main agent acts only as orchestrator; avoid reading code directly except small targeted reads; use scouts for investigation/root-cause analysis; dispatch forks for implementation; after one issue is fixed, stop and ask user to validate before continuing; no code-review/reviewer phase.

Connected open issues as of 2026-06-12:
- #135 After resume session doesn't continue — https://github.com/ineersa/agent-core/issues/135
- #134 Tool-call/tool-result ordering error — https://github.com/ineersa/agent-core/issues/134
- #133 Errors on exit — https://github.com/ineersa/agent-core/issues/133
- #131 TUI is missing tool responses — https://github.com/ineersa/agent-core/issues/131
- #130 SafeGuard doesn't work properly — https://github.com/ineersa/agent-core/issues/130
- #129 vLLM as provider seems to be broken — https://github.com/ineersa/agent-core/issues/129
- #127 TUI on /resume is not re-rendered properly — https://github.com/ineersa/agent-core/issues/127
- #125 Compat flags in providers — https://github.com/ineersa/agent-core/issues/125
- #124 Read + parallel bug? — https://github.com/ineersa/agent-core/issues/124
- #123 Completion bug for @ files completions — https://github.com/ineersa/agent-core/issues/123
- #122 Token usage in footer incorrect — https://github.com/ineersa/agent-core/issues/122
- #121 After run was cancelled followup message not starting it back — https://github.com/ineersa/agent-core/issues/121
- #120 Thinking as editor border color — https://github.com/ineersa/agent-core/issues/120
- #119 CTRL+V and paste image — https://github.com/ineersa/agent-core/issues/119
- #118 Delay after submit — https://github.com/ineersa/agent-core/issues/118
- #117 Shift+Tab not showing thinking level chosen — https://github.com/ineersa/agent-core/issues/117

## Acceptance criteria
- Each connected issue is investigated by a scout before implementation.
- Implementation for each issue is done by a fork, not directly by the orchestrator.
- After each individual issue fix, pause and ask the user to validate before continuing to the next issue.
- For TUI/runtime changes, fork includes required TmuxHarness E2E proof and Castor validation per project rules.
- Task remains IN-PROGRESS during one-by-one cleanup until user explicitly asks to move forward.

## Workflow metadata
Status: IN-PROGRESS
Branch: task/backlog-open-issues-cleanup
Worktree: /home/ineersa/projects/agent-core-worktrees/backlog-open-issues-cleanup
Fork run: d5ci1e50e3ee
PR URL:
PR Status:
Started: 2026-06-12T16:54:23.580Z
Completed:

## Work log
- Created: 2026-06-12T16:54:16.266Z

## Task workflow update - 2026-06-12T16:54:23.580Z
- Moved TODO → IN-PROGRESS.
- Created branch task/backlog-open-issues-cleanup.
- Created worktree /home/ineersa/projects/agent-core-worktrees/backlog-open-issues-cleanup.
- Copied vendor directory into /home/ineersa/projects/agent-core-worktrees/backlog-open-issues-cleanup.
- Copied .vera index into /home/ineersa/projects/agent-core-worktrees/backlog-open-issues-cleanup.
- Summary: Started umbrella backlog cleanup per user request. Will investigate and fix one GitHub issue at a time using scouts for root-cause analysis and forks for implementation, then stop for user validation after the first fix.

## Task workflow update - 2026-06-12T16:57:38.802Z
- Recorded fork run: 7nz1rximl6ud
- Started issue #117 first (oldest open issue): Shift+Tab not showing thinking level chosen.
- Scout completed root-cause analysis: footer intentionally hides reasoning text and tests enforce absence; Shift+Tab updates state without immediate footer invalidation; TUI E2E proof required.
- Fork 7nz1rximl6ud dispatched in worktree to implement only #117 with unit + TmuxHarness E2E validation.

## Task workflow update - 2026-06-12T17:08:20.196Z
- Validation: Fork reported: LLM_MODE=true castor check passed fully, including deptrac, all unit shards, controller E2E, llm-real, tui E2E, phpstan, and cs-check.; Fork reported new TUI E2E test: tests/Tui/E2E/ReasoningCycleTest.php proving Shift+Tab cycles off→minimal→low and shows status text.; Commit reported by fork: 482cf235b7f53b7e3a981acd1451b9aa1fe6308d.
- Summary: Issue #117 implementation completed by fork 7nz1rximl6ud. Fix restores visible thinking level feedback when Shift+Tab cycles reasoning by showing the new level in the status panel and adds a real TmuxHarness E2E proof. Work intentionally left task IN-PROGRESS and did not open PR or run reviewer per user instruction to stop after one issue for validation.
- Stopped after fixing issue #117 as requested. Awaiting user validation before moving to the next open GitHub issue.

## Task workflow update - 2026-06-12T17:08:55.824Z
- Post-fork verification found the implementation commit 482cf235 is on the integration checkout branch `main` and included in `origin/main` after task metadata push; the task worktree branch `task/backlog-open-issues-cleanup` remains at 23419aea and does not contain the code commit. Pausing for user validation before any further workflow/branch cleanup or next issue.

## Task workflow update - 2026-06-12T17:10:22.412Z
- User validation failed issue #117: provided snapshot `/home/ineersa/projects/agent-core-worktrees/backlog-open-issues-cleanup/.hatfield/tmp/tui/snapshots/snapshot-ansi-20260612-130848.ansi`; Shift+Tab still shows nothing. Need fork iteration with real agent tmux verification and proper snapshots per AGENTS.md, not only test harness assumptions.

## Task workflow update - 2026-06-12T17:10:58.765Z
- Recorded fork run: e3ji2jgnic2e
- Dispatched iteration fork e3ji2jgnic2e for issue #117 after failed user validation. Fork instructed to inspect the user's snapshot artifact, diagnose why prior fix did not show in real TUI, implement only #117, and verify via real interactive agent tmux with sent Shift+Tab keys and captured snapshots in addition to Castor TUI/check validation.

## Task workflow update - 2026-06-12T17:21:43.172Z
- Validation: castor test:tui --filter ReasoningCycle: OK per fork report.; castor test --filter FooterStateSegmentProvider: OK per fork report.; castor test --filter 'ModelControl|FooterState|Reasoning': OK per fork report.; Manual real TmuxHarness capture: OK per fork report; first Shift+Tab showed `reasoning    minimal`, second showed `reasoning    low`.; castor phpstan: OK per fork report.; LLM_MODE=true castor check: all steps OK except phpstan timed out inside check (exit 124); standalone castor phpstan passed. Not claiming a fully green full check until rerun passes without timeout.
- Summary: Issue #117 iteration completed by fork e3ji2jgnic2e after failed user validation. New fix shows reasoning status at startup and after every Shift+Tab press, including no-op/non-thinking model cases by falling back to display reasoning. Fork committed c82683aab9166a9e9302b03b872fade894fbb15e on task/backlog-open-issues-cleanup.
- Awaiting user validation of issue #117 iteration before moving to the next GitHub issue.

## Task workflow update - 2026-06-12T17:29:37.804Z
- User validation found additional #117 reasoning-level issues after c82683aa: xhigh appears in footer but should not; reasoning status is shown persistently/at startup but should appear only when reasoning is actually changed and disappear/overwrite when work/status changes; Shift+Tab should do nothing for models without thinking support and color must remain correct; Ctrl+P and /model changes must clamp/set reasoning to highest available level when the selected model does not support the persisted level (e.g. xhigh); reopening Hatfield must initialize reasoning correctly (user observed llama_cpp opening with high).

## Task workflow update - 2026-06-12T17:30:01.660Z
- Recorded fork run: 7h6a58rv1hlx
- Dispatched iteration fork 7h6a58rv1hlx for #117 reasoning-level correctness issues: remove persistent/startup reasoning status; prevent xhigh/status text leakage into footer; make Shift+Tab a no-op for non-thinking models; clamp reasoning correctly on Ctrl+P, /model change, and reopen/startup; verify with TmuxHarness E2E plus real interactive tmux snapshots.

## Task workflow update - 2026-06-12T17:47:56.805Z
- Validation: Fork reported full LLM_MODE=true castor check passed all 13 steps: deptrac, 7 unit shards, controller, llm-real, tui, phpstan, cs-check.; Fork reported castor test passed all 2,466 unit tests.; Fork reported castor test:tui passed all 17 TUI E2E tests including updated ReasoningCycleTest.; Fork reported manual tmux verification: startup has no reasoning status line; Shift+Tab shows `reasoning    minimal` in status panel only; footer remains clean; second Shift+Tab shows `reasoning    low`.; Commit reported: 61ceed95 on task/backlog-open-issues-cleanup.
- Summary: Issue #117 follow-up iteration completed by fork 7h6a58rv1hlx. Commit 61ceed95 fixes reasoning status lifecycle and model-support/clamping behavior: reasoning status is not seeded at startup, Shift+Tab is no-op for non-thinking models, reasoning status uses panel-only registry so it no longer leaks xhigh/text into footer, startup clamps non-thinking models to off, and existing model switch paths use display reasoning/clamping.
- Awaiting user validation of #117 reasoning-level fixes before proceeding to the next GitHub issue.

## Task workflow update - 2026-06-12T17:59:00.944Z
- Validation: User validation: #117 good/seems done.; Full LLM_MODE=true castor check passed on task branch per fork 7h6a58rv1hlx before merge.; Merged commits pushed to origin/main: cf9f7c72, 1ad4d68a.; Closed GitHub issue #117 via gh issue close.
- Summary: Issue #117 validated by user and merged into main via cherry-picks cf9f7c72 and 1ad4d68a from task branch fixes. Pushed to origin/main. GitHub issue #117 closed with commit reference. Umbrella task remains IN-PROGRESS for remaining issues.
- Per user instruction, merged validated #117 fix into main, kept the umbrella task/worktree for continued backlog cleanup, and closed only issue #117.

## Task workflow update - 2026-06-12T18:03:37.404Z
- User approved stacking workflow going forward: implement next issue on `task/backlog-open-issues-cleanup`, validate one issue, merge/push that branch to main, sync task branch back to latest main, then continue. Starting next open issue investigation.

## Task workflow update - 2026-06-12T18:07:12.951Z
- Scout investigated issue #118 Delay after submit. Root cause hypothesis: SubmitListener clears editor immediately, then performs synchronous session creation/context discovery/skills/system prompt/runtime start/update metadata before any visible feedback, and user message only appears later via canonical runtime events/poller. Recommended fix: show working status immediately before heavy work, consider optimistic user-message display with canonical replacement, explicitly ensure working visible, and validate with TmuxHarness timing/snapshot proof.

## Task workflow update - 2026-06-12T18:07:41.832Z
- Recorded fork run: d5ci1e50e3ee
- Dispatched implementation fork d5ci1e50e3ee for issue #118 Delay after submit. Fork instructed to implement immediate submit feedback in TUI, avoid duplicate/non-canonical transcript issues, add real TmuxHarness E2E timing proof, perform manual real-agent tmux snapshot verification, and run Castor validation.

## Task workflow update - 2026-06-12T18:19:52.206Z
- Validation: Fork reported full LLM_MODE=true castor check passed all 13 steps: deptrac, 7 unit shards, controller, llm-real, tui, phpstan, cs-check.; Fork reported castor test passed all 2,466 unit tests.; Fork reported castor test:tui passed all 19 TUI E2E tests including ImmediateSubmitFeedbackTest.; Manual tmux verification per fork: pre-submit `● idle`; post-submit `◐ Working...` appeared in ~106ms; post-response returned to `● idle`; exactly one user block and one assistant response.; Manual snapshot paths reported: var/tmp/tui-e2e-submit-manual3-7cce64/.hatfield/tmp/tui/smoke/02-post-submit.ansi and 03-post-response.ansi.; Commit reported: c53ec19b on task/backlog-open-issues-cleanup.
- Summary: Issue #118 implementation completed by fork d5ci1e50e3ee. Commit c53ec19b moves submit Working feedback before synchronous runtime setup and forces an immediate TUI repaint so the user sees `◐ Working...` quickly after pressing Enter. Fork intentionally did not add optimistic transcript blocks to avoid duplicate/canonical replay issues.
- Awaiting user validation of issue #118 before merging/closing and moving to the next GitHub issue.

## Task workflow update - 2026-06-12T18:29:55.000Z
- Validation: User validation: #118 feels way nicer and approved merge.; Fork validation before merge: full LLM_MODE=true castor check passed all 13 steps on the task branch.; Merged/pushed commit: 77918076 fix(tui): show working indicator immediately after submit.; Closed GitHub issue #118 via gh issue close.
- Summary: Issue #118 validated by user and merged using the stacking workflow. Rebased task/backlog-open-issues-cleanup onto origin/main, fast-forwarded main to include commit 77918076 (rebased from c53ec19b), pushed to origin/main, and closed GitHub issue #118. Umbrella task remains IN-PROGRESS for remaining issues.
- Used the approved stacking workflow: rebase task branch onto latest origin/main, fast-forward main to task branch, push, close only validated issue, then continue backlog cleanup.

## Task workflow update - 2026-06-12T18:34:39.388Z
- Summary: Issue #119 investigation completed by scout. Root cause: terminals generally do not send image clipboard data on Ctrl+V; Symfony TUI/editor paste handling is text-only and strips binary/control bytes; runtime user message path is text-only while image support currently exists via the agent-side view_image tool. Implementation needs product/UX decisions before dispatching a fork.
- Paused before implementing #119 to ask user product questions as requested. Key decisions: supported terminals/protocols, where pasted images should be stored, whether pasted image inserts a path/markdown reference vs direct attachment, and desired unsupported-terminal fallback.

## Task workflow update - 2026-06-12T18:41:58.561Z
- Validation: Created tracked task: tasks/TODO/tui-image-paste-support.md.; Closed GitHub issue #119 via gh issue close with implementation-as-separate-task comment.
- Summary: Issue #119 was classified as a larger feature/design task rather than a quick backlog fix. Created tasks/TODO/tui-image-paste-support.md with scout findings and acceptance criteria, then closed GitHub issue #119 with a comment pointing to the task.
- No code implementation for #119 in backlog cleanup. Proceed to next open issue after task creation/closure.
