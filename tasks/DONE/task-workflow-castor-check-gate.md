# Enforce Castor check gate before code review

## Goal
Update `.pi/extensions/task-workflow.ts` so moving a task from IN-PROGRESS to CODE-REVIEW requires an extension-run `LLM_MODE=true castor check` quality gate at the task branch HEAD before pushing/creating a PR. The gate must have an OS-level timeout to prevent model/E2E hangs, must keep the task in IN-PROGRESS on failure/timeout, and must record a commit-bound validation receipt in the task log/metadata. Do not rely on model-provided proof.

## Acceptance criteria
- IN-PROGRESS → CODE-REVIEW runs `LLM_MODE=true castor check` in the task worktree before push/PR by default.
- The check uses a hard timeout (default around 240s) with child-process cleanup; timeout/failure aborts the transition without moving the task.
- Gate verifies HEAD is unchanged and worktree remains clean after validation.
- Task metadata/log records passed status, commit sha, command, timeout, timestamp, and a sha256/output receipt or concise output summary.
- Gate can be explicitly bypassed only with a non-empty reason that is loudly recorded in task log/metadata.
- Existing move_task behavior for TODO→IN-PROGRESS and CODE-REVIEW→DONE remains intact.
- Validation via Castor passes, including tests covering gate pass/fail/timeout/bypass behavior if practical.

## Workflow metadata
Status: DONE
Branch: task/task-workflow-castor-check-gate
Worktree: /home/ineersa/projects/agent-core-worktrees/task-workflow-castor-check-gate
Fork run: 9cu31535t48h
PR URL: https://github.com/ineersa/agent-core/pull/83
PR Status: merged
Started: 2026-06-02T21:36:06.120Z
Completed: 2026-06-03T00:13:24.025Z

## Work log
- Created: 2026-06-02T21:35:52.947Z

## Task workflow update - 2026-06-02T21:36:06.120Z
- Moved TODO → IN-PROGRESS.
- Created branch task/task-workflow-castor-check-gate.
- Created worktree /home/ineersa/projects/agent-core-worktrees/task-workflow-castor-check-gate.
- Copied vendor directory into /home/ineersa/projects/agent-core-worktrees/task-workflow-castor-check-gate.
- Copied .vera index into /home/ineersa/projects/agent-core-worktrees/task-workflow-castor-check-gate.
- Summary: Claimed for implementation. Parent plan: add hard Castor quality gate inside `.pi/extensions/task-workflow.ts` for IN-PROGRESS → CODE-REVIEW before push/PR; use OS-level timeout around `env LLM_MODE=true castor check`, verify current HEAD/worktree cleanliness before and after, record commit-bound receipt, and support explicit non-empty bypass reason only. Fork will implement and commit locally only.

## Task workflow update - 2026-06-02T21:42:38.254Z
- Recorded fork run: qj8usxjrkcvn
- Validation: Fork reported: castor test PASS (1462 tests, 4273 assertions, 0 risky); castor deptrac PASS; castor phpstan PASS; castor cs-check PASS; Parent verified git status/log/show: HEAD 1af406a3, changed file `.pi/extensions/task-workflow.ts` only, 126 insertions / 5 deletions
- Summary: Fork qj8usxjrkcvn completed local commit 1af406a3 on task/task-workflow-castor-check-gate. Parent spot-check confirms `.pi/extensions/task-workflow.ts` is the only implementation file changed (+126/-5). Core gate design is present: MoveTaskParams gains `castorCheckTimeoutSeconds` and `skipCastorCheckReason`; IN-PROGRESS→CODE-REVIEW now runs `timeout --kill-after ... env LLM_MODE=true castor check` before push/PR, verifies HEAD unchanged and worktree clean, records Castor Check metadata receipt. Parent identified two small correctness polish issues to fix before review: (1) empty/whitespace `skipCastorCheckReason` currently falls through to running the gate instead of throwing as the parameter description/acceptance says; (2) `runCastorCheckGate()` computes `passed: result.code === 0` even if `result.killed` is true, so use `passed: !killed && result.code === 0` for defensive correctness.

## Task workflow update - 2026-06-02T21:50:53.288Z
- Validation: Parent verified branch HEAD f7b43d6b and diff: `.pi/extensions/task-workflow.ts` only; LLM_MODE=true castor check => FAIL only on known test:tui/TuiAgentSmokeTest SafeGuard write_outside_cwd model loop; other steps passed; reviewer subagent => REQUEST CHANGES: block root fallback when worktree missing
- Summary: Parent verification of follow-up fork lklb0hstmzc7: commit f7b43d6b exists and fixes both requested small issues (`passed: !killed && result.code === 0`; explicit throw for empty/whitespace skipCastorCheckReason). Parent grep found no reported bypass typo. Full `LLM_MODE=true castor check` on worktree failed only at known TUI/LLM SafeGuard tool-loop flake (TuiAgentSmokeTest timed out waiting for error marker after model attempted write outside cwd); deptrac/test/controller/llm-real/phpstan/cs-check steps passed. Reviewer subagent returned REQUEST CHANGES with one real blocker: missing/deleted task worktree falls back to integration root, so gate can validate wrong code before push. Additional reviewer issues to address: post-gate dirty check tied to same fallback, abort-signal kills mislabeled as timeout, metadata fields clutter new task template, killAfter formula can be simplified, optionally clearer missing `timeout`/`castor` command diagnostics. Next fork will fix reviewer issues before moving to CODE-REVIEW.

## Task workflow update - 2026-06-02T21:57:29.895Z
- Validation: reviewer subagent after 41578fea => APPROVED (no blocking issues); LLM_MODE=true castor check => PASS: deptrac ok; test ok (1462 tests, 4273 assertions); test:controller ok; test:llm-real ok; test:tui ok; phpstan ok; cs-check ok; quality ok; fork validation: castor cs-check PASS; castor test --filter=PathResolverTest PASS (44 tests, 52 assertions); castor test PASS; castor deptrac PASS; castor phpstan PASS
- Summary: Follow-up fork 7aprwiy39d91 completed commit 41578fea addressing all reviewer REQUEST CHANGES: worktree is now mandatory for CODE-REVIEW transition (no root fallback), gate always runs/checks task worktree, timeout/abort/failure labels are distinct, Castor metadata removed from new task template, kill-after simplified to fixed 15s, and `which timeout`/`which castor` diagnostics added. Parent re-ran reviewer subagent after fixes; reviewer returned APPROVED. Parent also ran full `LLM_MODE=true castor check` successfully on the task worktree.
- Reviewer gate receipt: approved current HEAD 41578fea on branch task/task-workflow-castor-check-gate.

## Task workflow update - 2026-06-02T21:57:43.839Z
- Moved IN-PROGRESS → CODE-REVIEW.
- Pushed task/task-workflow-castor-check-gate to origin.
- branch 'task/task-workflow-castor-check-gate' set up to track 'origin/task/task-workflow-castor-check-gate'.
- Created PR: https://github.com/ineersa/agent-core/pull/83
- Validation: reviewer subagent => APPROVED at current HEAD 41578fea; LLM_MODE=true castor check => PASS all steps: deptrac, test, test:controller, test:llm-real, test:tui, phpstan, cs-check, quality ok; castor test => PASS (1462 tests, 4273 assertions, 0 risky); castor deptrac => PASS (0 violations); castor phpstan => PASS (0 errors); castor cs-check => PASS (0 files fixed)
- Summary: Implements hard Castor quality gate in `.pi/extensions/task-workflow.ts` for `move_task` IN-PROGRESS → CODE-REVIEW. The gate runs `LLM_MODE=true castor check` in the task worktree before push/PR with OS-level `timeout --kill-after=15s`, default 240s timeout (configurable 60–900s), mandatory worktree presence (no integration-root fallback), HEAD unchanged and clean-worktree checks before/after, explicit non-empty skip reason bypass, and commit-bound receipt metadata (status, commit, command, timeout, completed timestamp, output SHA256). Reviewer subagent approved current HEAD and full LLM-mode Castor check passed.

## Task workflow update - 2026-06-02T22:02:05.751Z
- Moved CODE-REVIEW → IN-PROGRESS.
- Summary: Pulled back from CODE-REVIEW for one additional workflow improvement requested by user: auto-commit (and preferably push) exact task-file mutations from create_task/move_task/update_task so task workflow operations do not leave the integration checkout dirty or accidentally leak local task-board commits into later task branches. Also clarify Castor gate timeout wording: `--kill-after=15s` is the grace/kill escalation after the configured timeout, not the total timeout; default quality gate timeout remains 240s and configurable 60–900s.

## Task workflow update - 2026-06-02T22:12:23.531Z
- Validation: Fork validation: castor test PASS (1462 tests, 4273 assertions); deptrac PASS; phpstan PASS; cs-check PASS; Parent LLM_MODE=true castor check => FAIL only in test:tui TuiAgentSmokeTest known SafeGuard write_outside_cwd/model-loop flake; other steps PASS; Reviewer subagent => REQUEST CHANGES: create_task must use withFileMutationQueue; add empty paths guard; document post-move push-failure disk state
- Summary: Fork q2qblypi79st completed commit dd0332e2 adding auto-commit/auto-push helper for create_task/move_task/update_task and clarifying Castor timeout wording. Parent verification confirmed branch ahead of origin by 1 with `.pi/extensions/task-workflow.ts` only. Reviewer subagent returned REQUEST CHANGES: blocking issue is create_task is still outside withFileMutationQueue, so commitTaskFileChanges can interleave with move/update git staging; also requested defense guard for empty path array in commitTaskFileChanges, and noted commit after move can leave file moved but uncommitted if push fails (acceptable if error message documents state). Full LLM_MODE=true castor check failed only on known TuiAgentSmokeTest SafeGuard model loop flake; deptrac/test/controller/llm-real/phpstan/cs-check passed.

## Task workflow update - 2026-06-02T22:19:31.890Z
- Validation: reviewer subagent => APPROVED at HEAD 8c999c40; only non-blocking robustness/nice-to-have notes; LLM_MODE=true castor check => PASS: deptrac ok; test ok (1462 tests, 4273 assertions); test:controller ok; test:llm-real ok; test:tui ok (5 tests, 18 assertions); phpstan ok; cs-check ok; quality ok; fork validation: castor cs-check PASS; castor test --filter=PathResolverTest PASS; castor deptrac PASS; castor phpstan PASS; castor test PASS (1462 tests, 4273 assertions)
- Summary: Fork v544sr00owj5 completed commit 8c999c40 on top of dd0332e2 fixing reviewer blockers: create_task now uses withFileMutationQueue, commitTaskFileChanges has empty paths guard, push-failure message explicitly says metadata is already committed locally and `git push` is required, and timeout wording now says full castor check soft timeout. Parent re-reviewed via reviewer subagent; reviewer APPROVED current HEAD 8c999c40 with no blocking issues. Parent reran full LLM_MODE=true castor check successfully.
- Reviewer gate receipt: approved current HEAD 8c999c40 on branch task/task-workflow-castor-check-gate.

## Task workflow update - 2026-06-02T22:19:45.534Z
- Moved IN-PROGRESS → CODE-REVIEW.
- Pushed task/task-workflow-castor-check-gate to origin.
- branch 'task/task-workflow-castor-check-gate' set up to track 'origin/task/task-workflow-castor-check-gate'.
- PR already exists: https://github.com/ineersa/agent-core/pull/83
- Validation: reviewer subagent => APPROVED at HEAD 8c999c40; LLM_MODE=true castor check => PASS all steps: deptrac, test, test:controller, test:llm-real, test:tui, phpstan, cs-check, quality ok; castor test => PASS (1462 tests, 4273 assertions); castor deptrac => PASS (0 violations); castor phpstan => PASS (0 errors); castor cs-check => PASS (0 files fixed)
- Summary: Updated PR #83 branch with auto-commit/auto-push task-file mutation support plus reviewer fixes. New behavior: create_task/move_task/update_task commit exact touched `tasks/...` paths after mutation, verify no unrelated staged files are included, auto-push when upstream exists, and fail loudly for create_task/move_task push failures so checkout-ahead state is visible. create_task now participates in the same mutation lock as move/update; empty path arrays are a no-op; timeout wording clarifies 240s is the full castor check soft timeout and `--kill-after=15s` is only kill grace.

## Task workflow update - 2026-06-02T23:08:49.067Z
- Moved CODE-REVIEW → IN-PROGRESS.
- Summary: Pulled PR #83 back from CODE-REVIEW for an additional approved ergonomics change: add project prompt templates named with `/task-*` prefix so workflow commands are easy to find by prefix. Planned templates: `/task-start`, `/task-finish`, `/task-review-iterate`, `/task-done`. These are soft prompts complementing the hard gates in `.pi/extensions/task-workflow.ts`.

## Task workflow update - 2026-06-02T23:14:31.671Z
- Validation: LLM_MODE=true castor check at 05d627cd => PASS: deptrac ok; test ok (1462 tests, 4273 assertions); test:controller ok; test:llm-real ok; test:tui ok (5 tests, 18 assertions); phpstan ok; cs-check ok; quality ok; reviewer subagent at 05d627cd => REQUEST CHANGES for prompt template wording/accuracy
- Summary: Prompt templates commit was amended locally to 05d627cd (typo fix) and parent ran reviewer + full check. Full `LLM_MODE=true castor check` passed at 05d627cd. Reviewer subagent returned REQUEST CHANGES on prompt template accuracy: remove PR #83-specific wording; avoid duplicate full Castor check in `/task-finish` because move_task already gates; replace flake-normalizing wording with explicit failure analysis/skipCastorCheckReason; clarify `/task-done` branch deletion default; add explicit task-file/worktree metadata retrieval in `/task-review-iterate`. Need small follow-up prompt-only fix before pushing.

## Task workflow update - 2026-06-02T23:19:54.300Z
- Validation: Prompt-only validation: git diff --check HEAD~1..HEAD PASS; Fork validation before final wording amend: git diff --check PASS; castor cs-check PASS (0 files fixed); Previous full validation at prompt commit 05d627cd: LLM_MODE=true castor check PASS all steps; Skipped rerunning full castor check after prompt-only final wording edit per user instruction; no PHP/TS/extension code changed
- Summary: Prompt-template follow-up fork qtl33omv2emj completed and parent amended tiny wording typo in task-done prompt. Current worktree HEAD is 1e33bd88. Prompt fixes address reviewer comments: no PR #83 wording, no duplicate full castor check in `/task-finish`, gate failure handling requires analysis and explicit skipCastorCheckReason, `/task-done` cleanup defaults are accurate, `/task-review-iterate` reads task metadata/worktree, `/task-start` says typically in TODO. User explicitly approved skipping another full castor check because only prompt Markdown changed after previous full pass.

## Task workflow update - 2026-06-02T23:20:05.722Z
- Moved IN-PROGRESS → CODE-REVIEW.
- Pushed task/task-workflow-castor-check-gate to origin.
- branch 'task/task-workflow-castor-check-gate' set up to track 'origin/task/task-workflow-castor-check-gate'.
- PR already exists: https://github.com/ineersa/agent-core/pull/83
- Validation: Prompt-only validation: git diff --check HEAD~1..HEAD PASS; Fork validation: git diff --check PASS; castor cs-check PASS (0 files fixed); Previous full validation before final prompt-only wording changes: LLM_MODE=true castor check PASS all steps; Skipped final full castor check per user instruction because only Markdown prompt templates changed
- Summary: Updated PR #83 branch with `/task-*` prompt templates plus final prompt wording fixes. Templates added: `/task-start`, `/task-finish`, `/task-review-iterate`, `/task-done` under `.pi/prompts/`. They provide soft workflow guidance for claiming tasks, finishing tasks, iterating PR review comments, and moving approved work to DONE, complementing the hard Castor gate and task metadata auto-commit behavior in the extension.

## Task workflow update - 2026-06-02T23:28:46.470Z
- Recorded fork run: z6cbc79kvti2
- Summary: Additional review change requested while PR #83 is in code review: launched fork z6cbc79kvti2 to stabilize TUI multiturn smoke by changing prompts to explicit chat-only responses and forcing Hatfield SafeGuard extension enabled with blocking defaults in isolated TUI E2E settings. Scope limited to tests/Tui/E2E/TuiAgentSmokeTest.php; validation requested via castor test:llm-real, castor test:tui, and castor cs-check.

## Task workflow update - 2026-06-02T23:29:28.666Z
- Moved CODE-REVIEW → IN-PROGRESS.

## Task workflow update - 2026-06-02T23:43:09.162Z
- Recorded fork run: 9cu31535t48h
- Validation: fork reported: castor cs-check PASS; fork reported: castor test:controller PASS (1 test, 7 assertions); fork reported: castor test:tui PASS (5 tests, 18 assertions); fork reported: castor test:llm-real PASS (7 tests, 40 assertions); parent spot-check: branch clean, HEAD fd0fd787 ahead of origin by 1; diff touches only tests/CodingAgent/Runtime/Controller/E2E/ControllerE2eTestCase.php and tests/Tui/E2E/TuiStartupSnapshotTest.php
- Summary: Fork 9cu31535t48h completed commit fd0fd787: forced Hatfield SafeGuard enabled with blocking defaults in all agent-runtime E2E test-generated settings. Updated TuiStartupSnapshotTest to use Symfony YAML parse/merge/dump and added SafeGuard settings to ControllerE2eTestCase heredoc; TuiAgentSmokeTest already had explicit SafeGuard from prior commit. Preserved existing reasoning behavior per helper (TuiStartupSnapshotTest and ControllerE2eTestCase keep default_reasoning: off; TuiAgentSmokeTest remains as previously reverted).

## Task workflow update - 2026-06-02T23:56:52.043Z
- Moved IN-PROGRESS → CODE-REVIEW.
- Pushed task/task-workflow-castor-check-gate to origin.
- branch 'task/task-workflow-castor-check-gate' set up to track 'origin/task/task-workflow-castor-check-gate'.
- PR already exists: https://github.com/ineersa/agent-core/pull/83
- Validation: castor cs-check PASS; fork 9cu31535t48h reported castor test:controller PASS, castor test:tui PASS, castor test:llm-real PASS for SafeGuard test-setting changes
- Summary: Addressed PR #83 review comments: removed Castor gate bypass support entirely from task-workflow.ts, renamed /task-finish prompt to /task-to-pr, revised workflow prompts per review (task-start no plan-file/double-validation/waiting guidance, task-review-iterate moves PR tasks back to IN-PROGRESS before implementation and passes exact fork instructions, task-done checks for surviving worktree cleanup), and added explicit Hatfield SafeGuard defaults in agent-runtime E2E test settings.

## Task workflow update - 2026-06-02T23:58:13.566Z
- Validation: LLM_MODE=true castor check PASS on /home/ineersa/projects/agent-core-worktrees/task-workflow-castor-check-gate: deptrac ok; test ok (1462 tests, 4273 assertions); test:controller ok (1 test, 7 assertions); test:llm-real ok (7 tests, 40 assertions); test:tui ok (5 tests, 18 assertions); phpstan ok; cs-check ok; quality ok
- Summary: Post-review validation after pushing commits fd0fd787 and a7081f50: full Castor gate passed manually because the currently loaded move_task implementation did not print a gate receipt for the pushed branch.

## Task workflow update - 2026-06-03T00:04:39.311Z
- Validation: castor cs-check PASS for prompt-only changes; grep check: no remaining "write a plan", "Analyze and fix", or "AGENTS.md rules" wording in .pi/prompts
- Summary: Addressed latest PR #83 prompt-template review comments in commit f7e8aa13: task-start now suggests scout subagents for context collection instead of re-reading AGENTS.md; task-review-iterate removed the "do not ask it to write a plan" wording; task-to-pr now says the parent analyzes gate failures, prepares exact implementation details, and passes them to a fork rather than coding the fix directly.

## Task workflow update - 2026-06-03T00:07:11.420Z
- Validation: castor cs-check PASS for prompt-only changes
- Summary: Added researcher subagent guidance to prompt templates in commit feed310a: task-start, task-to-pr, and task-review-iterate now instruct using researcher for web searches or web-based research when up-to-date external information is needed; scout wording narrowed to codebase context.

## Task workflow update - 2026-06-03T00:13:24.025Z
- Moved CODE-REVIEW → DONE.
- Merged task/task-workflow-castor-check-gate into integration checkout.
- Already up to date.
- Removed worktree /home/ineersa/projects/agent-core-worktrees/task-workflow-castor-check-gate.
- Pulled integration checkout: Already up to date..
- Validation: git pull --ff-only on main updated 75714466..2ad15ece with PR #83 changes
- Summary: PR #83 merged on GitHub and main fast-forwarded to 2ad15ece. Moving task to DONE after successful merge.

## Task workflow update - 2026-06-03T00:14:47.737Z
- Validation: LLM_MODE=true castor check PASS on main: deptrac ok; test ok (1462 tests, 4273 assertions); test:controller ok (1 test, 7 assertions); test:llm-real ok (7 tests, 40 assertions); test:tui ok (5 tests, 18 assertions); phpstan ok; cs-check ok; quality ok
- Summary: Post-merge validation on main passed after PR #83 merge.
