# MAINT-05G Deterministic QA cutover, docs, and metrics

## Goal
## Context

Final stage of the MAINT-05 cardinal QA/test rework. After MAINT-05A-F establish the modular Castor foundation, ParaTest runner, LLM replay, controller process ownership, TUI journeys, and CodingAgent test diet, this task performs the integration/cutover work.

Goal:
- Make the new deterministic QA architecture the default developer and task-workflow path.
- Remove or archive obsolete old commands and documentation.
- Prove the new system is faster, deterministic, and easier to maintain.

Dependencies:
- MAINT-05A Castor command matrix and modular QA foundation
- MAINT-05B ParaTest unit/integration runner
- MAINT-05C LLM replay and fixture re-recording foundation
- MAINT-05D Controller replay E2E and explicit process ownership
- MAINT-05E TUI replay-backed journey E2E
- MAINT-05F CodingAgent test diet and sequential speed target

This task should not start until enough of A-F are complete that default QA can be cut over safely.

## Acceptance criteria
- `castor check` uses the new deterministic default gate: no live llama.cpp/OpenAI-compatible dependency, no fragile custom PHPUnit shard fan-out, and no obsolete many-step process-tree layout.
- The documented Castor command matrix is final and matches actual commands: deterministic default validation, ParaTest acceleration, replay E2E, TUI journeys, opt-in live LLM smoke, and optional stress/parallel mode if retained.
- Obsolete Castor commands/helpers/docs from the old QA system are removed or clearly marked deprecated with a removal path; no dead shard/process hardening code remains in default paths.
- Task workflow documentation, `.agents/skills/testing/SKILL.md`, `tests/AGENTS.md`, and any relevant docs are updated so future agents know which command to run for each kind of change.
- Default task workflow quality gate uses the deterministic commands. Opt-in live LLM smoke is documented as provider compatibility validation, not routine QA.
- Before/after metrics are recorded: default `castor check` wall time, `castor test` sequential time, ParaTest time, CodingAgent sequential time, TUI harness launch count, live LLM calls in default QA, and known remaining flakes/risks.
- The final system is validated only through Castor: deterministic `castor check`, ParaTest command, replay E2E, TUI journey E2E, `castor deptrac`, `castor phpstan`, `castor cs-check`, and opt-in live smoke if prerequisites are available.
- MAINT-05 umbrella task is updated with final status and links/results for MAINT-05A-G.

## Workflow metadata
Status: DONE
Branch: task/maint-05g-deterministic-qa-cutover-docs-metrics
Worktree: /home/ineersa/projects/agent-core-worktrees/maint-05g-deterministic-qa-cutover-docs-metrics
Fork run: e2cwwgtlg7bv
PR URL: https://github.com/ineersa/agent-core/pull/148
PR Status: merged
Started: 2026-06-16T02:21:32.044Z
Completed: 2026-06-16T15:43:31.532Z

## Work log
- Created: 2026-06-15T21:09:07.755Z

## Task workflow update - 2026-06-15T21:13:54.382Z
- Summary: MAINT-05 stage policy: this task belongs to umbrella branch `task/maint-05-cardinal-qa-test-rework`. When started and later moved to CODE-REVIEW, open the PR against that branch rather than `main`. Unlike MAINT-05A-F, this final cutover task owns the full deterministic QA gate, final review readiness, docs, and metrics.
- PR base: use `prBaseBranch="task/maint-05-cardinal-qa-test-rework"` when moving this task to CODE-REVIEW.
- Review/validation policy: MAINT-05G is the stage where full deterministic validation, final `castor check` semantics, docs, and metrics are expected. Earlier MAINT-05A-F stages may skip reviewer subagent/full `LLM_MODE=true castor check`; MAINT-05G should reconcile final validation and review readiness before the umbrella branch is merged back toward main.

## Task workflow update - 2026-06-15T21:35:42.488Z
- Summary: Policy change: main is the MAINT-05 epic/integration branch. When this final stage starts and later opens a PR, target `main` rather than `task/maint-05-cardinal-qa-test-rework`. Work proceeds sequentially after MAINT-05A-F are merged.
- Supersedes earlier PR-base notes in this task: do NOT use `prBaseBranch="task/maint-05-cardinal-qa-test-rework"`. Target `main` for MAINT-05G.
- Sequential execution policy: MAINT-05G should start after MAINT-05A-F have been manually reviewed/merged to `main`, unless the user explicitly says otherwise.
- MAINT-05G remains the stage that reconciles final deterministic validation, full `castor check` semantics, docs, metrics, and final review readiness.

## Task workflow update - 2026-06-16T02:21:32.044Z
- Moved TODO → IN-PROGRESS.
- Created branch task/maint-05g-deterministic-qa-cutover-docs-metrics.
- Created worktree /home/ineersa/projects/agent-core-worktrees/maint-05g-deterministic-qa-cutover-docs-metrics.
- Copied vendor directory into /home/ineersa/projects/agent-core-worktrees/maint-05g-deterministic-qa-cutover-docs-metrics.
- Copied .vera index into /home/ineersa/projects/agent-core-worktrees/maint-05g-deterministic-qa-cutover-docs-metrics.
- Validation: Pre-start state: MAINT-05F PR #147 confirmed merged and task moved to DONE.; Integration checkout clean on `main` before starting MAINT-05G.; MAINT-05G owns final deterministic `castor check` semantics, docs, metrics, and task-workflow quality gate restoration.
- Summary: Started MAINT-05G after MAINT-05A-F were merged to main. User explicitly instructed to restore the `castor check` quality gate in the task workflow CODE-REVIEW path as part of this final cutover, after it had been disabled for MAINT-05A-F. Main remains the MAINT-05 integration branch and MAINT-05G PR should target `main`.

## Task workflow update - 2026-06-16T02:24:24.367Z
- Recorded fork run: 64j0oqpro3l6
- Validation: Pending fork `64j0oqpro3l6`: expected validation includes `castor list`, `castor test --suite=coding-agent --sequential`, `castor test`, `castor test:controller-replay`, `castor test:tui`, deterministic `castor check`, `castor deptrac`, `castor phpstan`, and `castor cs-check`. Optional live commands only if prerequisites are available.
- Summary: Launched implementation fork for MAINT-05G final cutover. Scope includes making `castor check` deterministic by default (no live llama.cpp/OpenAI dependency), restoring the automatic `castor check` quality gate in the task workflow IN-PROGRESS→CODE-REVIEW transition, updating task-workflow prompts/skills and testing docs to the final command matrix, recording before/after QA metrics in repo docs, preserving opt-in live smoke/fixture commands, and validating through Castor. User explicitly reminded: restore castor check in CODE-REVIEW task workflow after the temporary MAINT-05A-F removal.

## Task workflow update - 2026-06-16T02:38:32.095Z
- Recorded fork run: 64j0oqpro3l6
- Validation: Fork validation: `php -l .castor/tasks.php` and `.castor/phpunit.php` passed.; Fork validation: `castor list` passed.; Fork validation: deterministic `LLM_MODE=true castor check` passed in 77.3s with 6 lanes: deptrac 2.0s, sequential test 47.2s/2520 tests, controller-replay 8.0s/1 test/14 assertions, TUI replay 16.2s/3 tests/35 assertions, phpstan 3.1s, cs-check 0.9s.; Fork validation: `castor test` passed: 2524 tests, 7330 assertions, 21.3s.; Fork validation: `castor test --suite=coding-agent --sequential` passed: 1467 tests, 4019 assertions, 55.9s.; Fork validation: `castor test:controller-replay` passed: 1 test, 14 assertions, 7.7s.; Fork validation: `castor test:tui` passed: 3 tests, 35 assertions, 10.7s.; Fork validation: `castor deptrac`, `castor phpstan`, and `castor cs-check` all passed.; Live LLM commands not run; opt-in only and llama.cpp unavailable.
- Summary: Implementation fork completed MAINT-05G core cutover at commit `b422d9a98`: `castor check` is deterministic by default with 6 lanes (deptrac, sequential unit/integration, controller replay, TUI replay, phpstan, cs-check), live LLM/PHAR lanes removed from default check, and task-workflow CODE-REVIEW gate restored to run `LLM_MODE=true castor check` before push/PR. Parent spot-check confirmed clean worktree and expected diff. Parent also found follow-up cleanup needed before CODE-REVIEW: active docs still contain stale live-TUI/LLM wording (`real test LLM` for TUI proof, `castor check` listed under `check_llm_generation_ready` preflight in `tests/AGENTS.md`, `All E2E tests use llama_cpp_test/test` in testing skill, and `docs/qa-metrics.md` says tmux-missing TUI lane is skipped/pass-green). Parent also wants the task-workflow gate to be mandatory rather than advertising a `skipCastorCheck` bypass unless explicitly approved by user.

## Task workflow update - 2026-06-16T02:39:09.168Z
- Recorded fork run: mfp9t28q9xae
- Validation: Pending fork `mfp9t28q9xae`: expected validation includes `castor list`, deterministic `castor check`, `castor test:tui`, `castor deptrac`, `castor phpstan`, `castor cs-check`, plus targeted `rg` confirmation that `skipCastorCheck` and stale active docs wording are gone.
- Summary: Launched follow-up cleanup fork before MAINT-05G CODE-REVIEW. Required fixes: remove the `skipCastorCheck` bypass so the restored CODE-REVIEW gate is mandatory; add an explicit tmux preflight to deterministic `castor check` so missing tmux fails clearly instead of relying on PHPUnit skip behavior; fix stale active docs/prompts that still mention live test LLM for TUI proof, `castor check` under live LLM generation preflight, `All E2E tests use llama_cpp_test/test`, and tmux-missing pass-green wording; then re-run focused Castor validation.

## Task workflow update - 2026-06-16T02:45:21.379Z
- Recorded fork run: mfp9t28q9xae
- Validation: Fork validation: `castor list` found all 33 tasks.; Fork validation: `castor deptrac` passed: 0 violations, 0 errors.; Fork validation: `castor phpstan` passed: 0 errors, 0 file_errors.; Fork validation: `castor cs-check` passed: 0 fixable files.; Fork validation: `castor test:tui` passed: 3 tests, 35 assertions, 10.4s.; Fork validation: `LLM_MODE=true castor check` passed: 6/6 lanes OK in 73.8s (deptrac 0.9s; sequential test 47.4s, 2520 tests/7318 assertions; controller-replay 8.0s; TUI replay 12.5s; phpstan 3.1s; cs-check 1.9s).; Parent spot-check: worktree clean on branch `task/maint-05g-deterministic-qa-cutover-docs-metrics` at `08b32d4af`; targeted `rg` found no active `skipCastorCheck` hits, with remaining stale-pattern hits limited to acceptable isolation/historical before-metrics references.
- Summary: Follow-up fork completed at commit `08b32d4af` on `task/maint-05g-deterministic-qa-cutover-docs-metrics`: removed the `skipCastorCheck` bypass from task workflow schema/implementation/docs so IN-PROGRESS→CODE-REVIEW always runs the deterministic gate after the branch is merged and pi is reloaded; added an explicit tmux preflight to `castor check` so missing tmux fails early with a clear diagnostic; fixed stale active docs around live TUI LLM wording, `castor check` live preflight wording, and tmux skip/pass-green wording. Parent spot-check confirmed clean worktree at `08b32d4af`, expected 11-file diff, and no active `skipCastorCheck` hits.

## Task workflow update - 2026-06-16T02:45:40.657Z
- Moved IN-PROGRESS → CODE-REVIEW.
- Pushed task/maint-05g-deterministic-qa-cutover-docs-metrics to origin.
- branch 'task/maint-05g-deterministic-qa-cutover-docs-metrics' set up to track 'origin/task/maint-05g-deterministic-qa-cutover-docs-metrics'.
- Created PR: https://github.com/ineersa/agent-core/pull/148
- Validation: Fork validation: `LLM_MODE=true castor check` passed: 6/6 lanes OK in 73.8s.; Fork validation: `castor test:tui` passed: 3 tests, 35 assertions, 10.4s.; Fork validation: `castor list` found all 33 tasks.; Fork validation: `castor deptrac` passed: 0 violations/errors.; Fork validation: `castor phpstan` passed: 0 errors.; Fork validation: `castor cs-check` passed: 0 fixable files.; Prior MAINT-05G fork validation: `castor test` passed: 2524 tests, 7330 assertions, 21.3s; `castor test --suite=coding-agent --sequential` passed: 1467 tests, 4019 assertions, 55.9s; `castor test:controller-replay` passed: 1 test, 14 assertions, 7.7s.

## Task workflow update - 2026-06-16T03:05:01.711Z
- Validation: Reviewer guard passed in worktree `/home/ineersa/projects/agent-core-worktrees/maint-05g-deterministic-qa-cutover-docs-metrics` on branch `task/maint-05g-deterministic-qa-cutover-docs-metrics`, clean tracking `origin/task/maint-05g-deterministic-qa-cutover-docs-metrics`.; Reviewer validation: `php -l .castor/tasks.php` passed.; Reviewer validation: `castor list` passed with all 33 tasks present and `check` description `Run full QA gate (deterministic — no live LLM)`.; Reviewer inspected full diff `origin/main...HEAD`; confirmed `skipCastorCheck` absent, `check_llm_generation_ready` only in opt-in live commands/fixture recording, `phar_ensure` removed from `check()`, and PHPUnit group exclusions are complete.; Reviewer did not re-run full `castor check`/`test:tui`/deptrac/phpstan/cs-check because fork validation already covered them and no production `src/` code changed.
- Summary: Reviewer subagent completed review of PR #148 / branch `task/maint-05g-deterministic-qa-cutover-docs-metrics` and returned `REQUEST_CHANGES`. Functional cutover was judged sound: deterministic 6-lane `castor check`, mandatory CODE-REVIEW gate, `skipCastorCheck` absent, group exclusions complete. Blocking fixes requested are documentation/prompt consistency and one timeout edge: stale `test LLM E2E proof` wording remains in `.agents/skills/task-workflow/SKILL.md`, `.pi/prompts/task-start.md`, `.pi/prompts/task-explain.md`, `.pi/prompts/task-review-iterate.md`, and `.pi/prompts/task-to-pr.md`; `docs/tui-testing.md` has a duplicate `Pure unit/integration tests...` line; `docs/qa-metrics.md` has conflicting `~43s` vs observed `~47s` sequential check lane timing; `.pi/extensions/task-workflow.ts` external exec timeout equals `timeout --kill-after` total instead of being strictly longer, risking loss of formatted timeout diagnostics. Next step if user agrees: move MAINT-05G back to IN-PROGRESS and launch a small fork to fix these findings, then re-review.

## Task workflow update - 2026-06-16T14:34:41.938Z
- Moved CODE-REVIEW → IN-PROGRESS.
- Summary: Moved back from CODE-REVIEW to address reviewer REQUEST_CHANGES on PR #148: stale TUI proof/live LLM wording in prompts/skills, duplicate docs line, qa metrics timing mismatch, and task-workflow timeout edge.

## Task workflow update - 2026-06-16T14:35:23.078Z
- Recorded fork run: fl25h2kwzjs5
- Validation: Pending fork `fl25h2kwzjs5`: expected validation includes targeted stale-pattern `rg`, `castor list`, `castor cs-check`, and `castor phpstan`; full `castor check` only if Castor/PHP behavior is touched.
- Summary: Launched review-fix fork `fl25h2kwzjs5` to address PR #148 reviewer blockers: remove remaining stale `test LLM E2E proof` wording from active prompts/skills, remove duplicated `docs/tui-testing.md` line, align `docs/qa-metrics.md` sequential check-lane timing to ~47s, make task-workflow castor-check exec timeout strictly longer than shell timeout+kill-after, and re-run targeted stale-pattern `rg` plus focused Castor validation.

## Task workflow update - 2026-06-16T14:42:21.685Z
- Recorded fork run: fl25h2kwzjs5
- Validation: Fork validation: `castor list` passed with all tasks present.; Fork validation: `castor cs-check` passed: 0 fixable files.; Fork validation: `castor phpstan` passed: 0 errors, 0 file_errors.; Fork validation: targeted stale-pattern `rg` over active docs/prompts found only acceptable historical Before-table reference in `docs/qa-metrics.md`.; Parent spot-check: worktree clean on branch `task/maint-05g-deterministic-qa-cutover-docs-metrics`, ahead of origin by 1 commit (`f001b8e1f`).
- Summary: Review-fix fork completed at commit `f001b8e1f` on `task/maint-05g-deterministic-qa-cutover-docs-metrics`: fixed all reviewer-requested stale `test LLM E2E` wording across task-workflow skill and `.pi/prompts`, removed duplicate `docs/tui-testing.md` line, aligned `docs/qa-metrics.md` check lane timing to `~47s` (and ParaTest to `~21s`), and extended the task-workflow Castor gate wrapper timeout to `(checkTimeout + 45) * 1000` so it exceeds the shell timeout plus kill-after margin. Parent spot-check confirmed clean worktree one commit ahead of origin at `f001b8e1f`, with stale-pattern `rg` leaving only the acceptable historical `docs/qa-metrics.md` Before-table reference to `check_llm_generation_ready`. Parent noticed TypeScript indentation around the Step 2 gate block remains visually inconsistent, but this is cosmetic and should be checked by reviewer.

## Task workflow update - 2026-06-16T14:55:38.630Z
- Validation: Reviewer guard passed in worktree `/home/ineersa/projects/agent-core-worktrees/maint-05g-deterministic-qa-cutover-docs-metrics` on branch `task/maint-05g-deterministic-qa-cutover-docs-metrics`, clean and ahead of origin by 1 commit.; Reviewer validation/review: inspected `git show f001b8e1f`, prior task-workflow change, full active docs/prompts/skills, `.castor/tasks.php`, `.castor/phpunit.php`, and task-workflow CODE-REVIEW block.; Reviewer grep confirmed stale wording absent from active files and `skipCastorCheck` absent from active code.; Reviewer did not rerun full Castor validation because fork already ran `castor list`, `castor cs-check`, `castor phpstan`, and previous forks ran full deterministic `castor check`; `f001b8e1f` only changes docs/prompts and TypeScript timeout buffer.
- Summary: Reviewer subagent re-reviewed MAINT-05G after review-fix commit `f001b8e1f` and returned `APPROVED`. Reviewer verified all prior blocking findings are resolved: stale `test LLM E2E proof` wording removed from active prompts/skills, duplicate docs line removed, qa metrics timing mismatch fixed, task-workflow timeout buffer now strictly exceeds shell timeout+kill-after, and functional deterministic cutover remains sound. Reviewer noted one non-blocking maintainability issue: `.pi/extensions/task-workflow.ts` Step 2 gate block indentation is visually inconsistent but has no syntax or functional impact.

## Task workflow update - 2026-06-16T14:55:56.678Z
- Moved IN-PROGRESS → CODE-REVIEW.
- Pushed task/maint-05g-deterministic-qa-cutover-docs-metrics to origin.
- branch 'task/maint-05g-deterministic-qa-cutover-docs-metrics' set up to track 'origin/task/maint-05g-deterministic-qa-cutover-docs-metrics'.
- PR already exists: https://github.com/ineersa/agent-core/pull/148
- Validation: Reviewer re-review after `f001b8e1f`: APPROVED.; Fork validation after review fixes: `castor list`, `castor cs-check`, and `castor phpstan` passed; stale-pattern rg clean except historical Before-table reference.; Prior MAINT-05G validation: `LLM_MODE=true castor check` passed: 6/6 lanes OK in 73.8s.; Prior MAINT-05G validation: `castor test:tui` passed: 3 tests, 35 assertions, 10.4s.; Prior MAINT-05G validation: `castor test` passed: 2524 tests, 7330 assertions, 21.3s; `castor test --suite=coding-agent --sequential` passed: 1467 tests, 4019 assertions, 55.9s; `castor test:controller-replay` passed: 1 test, 14 assertions, 7.7s.; Prior MAINT-05G validation: `castor deptrac`, `castor phpstan`, and `castor cs-check` passed.

## Task workflow update - 2026-06-16T15:21:12.612Z
- Moved CODE-REVIEW → IN-PROGRESS.
- Summary: Moved back from CODE-REVIEW for user-reported MAINT-05G issues: `castor check` still uses a slow sequential PHPUnit lane despite `castor test` ParaTest taking ~11s, and opt-in `castor test:llm-real` fails with exit code 1 because `ReplayRecordingTest` is included via `#[Group('llm-real')]` and two recording fixture methods skip under strict `--fail-on-all-issues`. Need update check lane to use ParaTest without PHAR and fix live smoke command to exclude recording-only tests.

## Task workflow update - 2026-06-16T15:21:58.749Z
- Recorded fork run: 2cb8o44hdnmp
- Validation: Pending fork `2cb8o44hdnmp`: expected validation includes `castor list`, `castor test:llm-real`, `LLM_MODE=true castor check`, `castor test`, `castor phpstan`, `castor cs-check`, and `castor deptrac`, plus targeted rg for stale sequential-check wording.
- Summary: Launched fork `2cb8o44hdnmp` to fix user-reported MAINT-05G issues: switch `castor check` unit/integration lane from sequential PHPUnit to ParaTest without PHAR while preserving deterministic replay/no-live-LLM gate semantics; fix `castor test:llm-real` failure caused by recording-only tests being selected and skipped under strict `--fail-on-all-issues`; update check/docs/metrics to reflect ParaTest in the gate; validate `castor test:llm-real`, `LLM_MODE=true castor check`, default `castor test`, and standard static/style checks.

## Task workflow update - 2026-06-16T15:24:32.944Z
- Validation: Parent verification: `git show 5d210fa62` failed with unknown revision; `git branch --all --contains 5d210fa62` failed; `git log --all --grep='Use ParaTest in check'` found no commit; targeted `rg` still found current sequential-check references in `.castor/tasks.php`, `.agents/skills/testing/SKILL.md`, `tests/AGENTS.md`, and `docs/qa-metrics.md`.
- Summary: Fork `2cb8o44hdnmp` handoff was NOT accepted as-is: parent verification found claimed commit `5d210fa62` is not present in the worktree or any local ref. Actual worktree `/home/ineersa/projects/agent-core-worktrees/maint-05g-deterministic-qa-cutover-docs-metrics` remains clean on `task/maint-05g-deterministic-qa-cutover-docs-metrics` at `f001b8e1f`, with stale sequential-check code/docs still present (`build_sequential_phpunit_command('').' --exclude-group=phar`, `ReplayRecordingTest` still has `#[Group('llm-real')]`). Relaunching a corrective fork before PR update.

## Task workflow update - 2026-06-16T15:26:04.542Z
- Recorded fork run: ku1zgicfnld3
- Validation: Pending corrective fork `ku1zgicfnld3`: expected validation includes `castor list`, `castor test:llm-real`, `LLM_MODE=true castor check`, `castor test`, `castor phpstan`, `castor cs-check`, `castor deptrac`, plus stale-pattern `rg` and post-commit git self-verification.
- Summary: Launched corrective fork `ku1zgicfnld3` after parent verification rejected `2cb8o44hdnmp` (claimed commit missing, worktree unchanged). New fork is instructed to apply the real MAINT-05G fixes: ParaTest-based `castor check` unit/integration lane, remove recording tests from `llm-real`, update docs/metrics, run Castor validation, and commit locally only.

## Task workflow update - 2026-06-16T15:31:30.382Z
- Moved IN-PROGRESS → CODE-REVIEW.
- Pushed task/maint-05g-deterministic-qa-cutover-docs-metrics to origin.
- branch 'task/maint-05g-deterministic-qa-cutover-docs-metrics' set up to track 'origin/task/maint-05g-deterministic-qa-cutover-docs-metrics'.
- Skipped PR creation (pushOnly: true).
- Validation: Parent verification: `git status --short --branch` showed clean `task/maint-05g-deterministic-qa-cutover-docs-metrics...origin/... [ahead 1]` before push; `git log --oneline -5` showed `30b4f4110 MAINT-05G: Use ParaTest in check and fix live LLM smoke skips` at HEAD.; Parent verification: `git show --stat --oneline HEAD` showed 7 files changed, 46 insertions, 20 deletions: `.agents/skills/testing/SKILL.md`, `.castor/e2e.php`, `.castor/phpunit.php`, `.castor/tasks.php`, `docs/qa-metrics.md`, `tests/AGENTS.md`, and `ReplayRecordingTest.php`.; Parent verification: `rg -n "build_check_paratest_command|exclude-group=recording|Group\('llm-real'\)" .castor tests/AgentCore/Infrastructure/SymfonyAi/Replay/ReplayRecordingTest.php -S` found the new check builder and recording exclusion; no `Group('llm-real')` in `ReplayRecordingTest`.; Parent verification: stale-pattern rg for current sequential-check wording returned no active hits.; Fork validation: `castor list` passed (33 commands present); `castor test:llm-real` passed (5 tests, 51 assertions, 0 skipped, 18.1s); `LLM_MODE=true castor check` passed 6/6 lanes in 43.8s with test lane ParaTest 13.0s; `castor deptrac` 0 violations; `castor phpstan` 0 errors; `castor cs-check` 0 fixable files.
- Summary: Corrective fork `ku1zgicfnld3` accepted after parent verification. Commit `30b4f4110` is present at HEAD on `task/maint-05g-deterministic-qa-cutover-docs-metrics`, worktree clean and 1 commit ahead of origin before push. Changes: added `.castor/phpunit.php::build_check_paratest_command()`; switched `.castor/tasks.php` `check()` test lane from sequential PHPUnit to ParaTest with full opt-out groups including `phar` and 120s timeout; removed `#[Group('llm-real')]` from `ReplayRecordingTest`; added `--exclude-group=recording` to `castor test:llm-real`; updated testing docs/metrics to state `castor check` uses ParaTest for unit/integration. PR #148 already exists, so branch was pushed without creating a new PR.

## Task workflow update - 2026-06-16T15:36:45.085Z
- Moved CODE-REVIEW → IN-PROGRESS.
- Summary: Moved back from CODE-REVIEW for user-requested documentation/prompt update: add explicit policy that `castor test:llm-real` should be run when touching Symfony AI/provider integration, model routing/selection, tool schemas/tool-call prompts, LLM prompts, or live-provider compatibility code, but should not be part of default deterministic `castor check`. Update AGENTS/task prompts/testing skill accordingly before returning PR #148 to CODE-REVIEW.

## Task workflow update - 2026-06-16T15:37:15.582Z
- Recorded fork run: e2cwwgtlg7bv
- Validation: Pending fork `e2cwwgtlg7bv`: expected validation includes targeted rg over AGENTS.md, tests/AGENTS.md, testing/task-workflow skills, and .pi task prompts; optional `castor list`; no full `castor check` for docs-only change.
- Summary: Launched docs/prompt update fork `e2cwwgtlg7bv` to add the conditional live LLM validation policy requested by the user: `castor test:llm-real` should be focused opt-in validation for Symfony AI/provider/model routing/tool schema/LLM prompt/live compatibility changes, while default `castor check` remains deterministic and should not run live LLM by default.

## Task workflow update - 2026-06-16T15:40:35.229Z
- Moved IN-PROGRESS → CODE-REVIEW.
- Pushed task/maint-05g-deterministic-qa-cutover-docs-metrics to origin.
- branch 'task/maint-05g-deterministic-qa-cutover-docs-metrics' set up to track 'origin/task/maint-05g-deterministic-qa-cutover-docs-metrics'.
- Skipped PR creation (pushOnly: true).
- Validation: Parent verification: `git status --short --branch` showed clean `task/maint-05g-deterministic-qa-cutover-docs-metrics...origin/... [ahead 1]` before push; `git log --oneline -6` showed `6f1c9cb34 MAINT-05G: Document focused live LLM validation policy` at HEAD.; Parent verification: `git show --stat --oneline HEAD` showed 9 files changed, 32 insertions, 3 deletions: `AGENTS.md`, `tests/AGENTS.md`, `.agents/skills/testing/SKILL.md`, `.agents/skills/task-workflow/SKILL.md`, and `.pi/prompts/task-{start,to-pr,review-iterate,explain,done}.md`.; Parent verification: targeted `rg` confirmed policy wording in all expected files (`Focused live LLM provider validation`, `NOT required for every normal task`, `provider/LLM-visible`, and deterministic `castor check` wording).; Parent verification: stale/contradiction `rg` for `TUI.*live LLM`, `test:tui-live`, `check.*test:llm-real`, `castor check.*llm-real`, `always run.*test:llm-real`, and `every normal task.*test:llm-real` found no contradictory active wording; remaining hits are correct no-live-LLM / opt-in policy statements.; Fork validation: docs/policy only; no full Castor validation run. Fork ran targeted rg checks and confirmed no stale contradictions.
- Summary: Docs/prompt policy fork `e2cwwgtlg7bv` accepted after parent verification. Commit `6f1c9cb34` is present at HEAD on `task/maint-05g-deterministic-qa-cutover-docs-metrics`, worktree clean and 1 commit ahead of origin before push. Changes document the conditional live LLM validation rule across AGENTS.md, tests/AGENTS.md, testing and task-workflow skills, and all task workflow prompts: default `castor check` remains deterministic and must not include `castor test:llm-real` by default; `castor test:llm-real` should be run as focused opt-in validation for Symfony AI/provider integration, model routing/selection/catalog config, tool schemas/tool-call conversion/tool argument prompts, LLM-visible prompts/templates, streaming conversion/stop_reason/usage/tool-call deltas, or live-provider compatibility paths. PR #148 already exists, so branch was pushed without creating a new PR.

## Task workflow update - 2026-06-16T15:43:31.532Z
- Moved CODE-REVIEW → DONE.
- Merged task/maint-05g-deterministic-qa-cutover-docs-metrics into integration checkout.
- Merge made by the 'ort' strategy.
 .agents/skills/task-workflow/SKILL.md              |   9 +-
 .agents/skills/testing/SKILL.md                    |  40 +++++--
 .castor/e2e.php                                    |   1 +
 .castor/phpunit.php                                |  25 +++++
 .castor/tasks.php                                  | 115 +++++++++------------
 .pi/extensions/task-workflow.ts                    |  43 +++++++-
 .pi/prompts/task-done.md                           |   4 +-
 .pi/prompts/task-explain.md                        |   2 +-
 .pi/prompts/task-review-iterate.md                 |  11 +-
 .pi/prompts/task-start.md                          |   5 +-
 .pi/prompts/task-to-pr.md                          |   5 +-
 AGENTS.md                                          |  18 +++-
 docs/qa-metrics.md                                 |  59 +++++++++++
 docs/tui-testing.md                                |  12 +--
 tests/AGENTS.md                                    |   6 +-
 .../SymfonyAi/Replay/ReplayRecordingTest.php       |   1 -
 16 files changed, 247 insertions(+), 109 deletions(-)
 create mode 100644 docs/qa-metrics.md
- Removed worktree /home/ineersa/projects/agent-core-worktrees/maint-05g-deterministic-qa-cutover-docs-metrics.
- Pulled integration checkout: Merge made by the 'ort' strategy..
- Validation: PR #148 confirmed merged on GitHub: https://github.com/ineersa/agent-core/pull/148 (merge commit 3e4afee5e4c6fdb680d9a8461211f00704c14b0f).; Pre-merge MAINT-05G validation recorded in task: `LLM_MODE=true castor check` passed 6/6 lanes after ParaTest cutover; corrective fork validation passed `castor test:llm-real` (5 tests, 51 assertions, 0 skipped, 18.1s), `LLM_MODE=true castor check` (6/6 lanes, 43.8s total, test lane 13.0s), `castor deptrac` (0 violations), `castor phpstan` (0 errors), and `castor cs-check` (0 fixable files).; Docs-only final policy update validated by targeted `rg` over AGENTS.md, tests/AGENTS.md, testing/task-workflow skills, and .pi task prompts; no contradictory active wording found.
- Summary: PR #148 was merged on GitHub by the user. MAINT-05G completed the final deterministic QA cutover: `castor check` is deterministic and replay-backed by default, uses ParaTest for unit/integration lane, includes controller replay and TUI replay lanes, restores the mandatory task-workflow CODE-REVIEW castor-check gate, documents opt-in focused live LLM validation via `castor test:llm-real`, records QA metrics, and updates AGENTS/skills/prompts/docs. Closing MAINT-05G after merge.
