# MAINT-05B Replace custom PHPUnit sharding with ParaTest

## Goal
## Context

Second stage of the cardinal QA/test rework. Replace our fragile custom Castor PHPUnit shard machinery with ParaTest for unit/integration suites that are safe to parallelize.

Current problem:
- Castor manually discovers/shards PHPUnit files (`coding-agent-1..4`, etc.).
- Shards are file-count/hand balanced and hard for models to reason about.
- Custom process fan-out leaks complexity into `.castor/tasks.php`.

Goal:
- Add `brianium/paratest` as the PHPUnit-level parallel runner.
- Use ParaTest for optional acceleration of safe unit/integration suites.
- Keep a deterministic sequential baseline so ParaTest is not hiding bad tests.

Dependency:
- Prefer doing this after MAINT-05A establishes the modular Castor command matrix.

Important direction:
- Castor may still run coarse independent lanes in parallel.
- ParaTest replaces custom PHPUnit sharding only.
- Do not use ParaTest by default for TUI journey tests.
- Do not use ParaTest by default for controller/messenger E2E unless later process ownership proves safe.

## Acceptance criteria
- `brianium/paratest` is added as a dev dependency compatible with the project's PHPUnit version, or the task documents a blocker if compatibility is unavailable.
- Manual Castor PHPUnit file sharding/round-robin code is removed or bypassed from default commands: no `coding-agent-1..4` style custom shard fan-out remains in the default unit/integration path.
- A Castor command exists for sequential deterministic unit/integration tests and a separate Castor command exists for ParaTest-powered parallel unit/integration tests.
- ParaTest workers use `TEST_TOKEN` / `UNIQUE_TEST_TOKEN` or equivalent to isolate SQLite DB path, Symfony cache dir, PHPUnit cache, JUnit/log/report files, and any temp artifacts.
- Database migration/bootstrap for ParaTest workers is explicit and safe. It does not rely on shared mutable DB/cache state between workers.
- The ParaTest command excludes TUI E2E, live LLM, and controller/messenger E2E groups by default unless those suites are explicitly proven safe.
- Old custom shard helper functions and worker command builders are deleted when no longer used.
- Validation uses Castor only: sequential unit/integration command, ParaTest command, `castor deptrac`, `castor phpstan`, and `castor cs-check`.
- Task handoff records sequential vs ParaTest timings and any tests that remain unsafe for ParaTest.

## Workflow metadata
Status: CODE-REVIEW
Branch: task/maint-05b-paratest-unit-integration-runner
Worktree: /home/ineersa/projects/agent-core-worktrees/maint-05b-paratest-unit-integration-runner
Fork run: 53azgrzs7ng7
PR URL: https://github.com/ineersa/agent-core/pull/142
PR Status: open
Started: 2026-06-15T22:05:20.925Z
Completed:

## Work log
- Created: 2026-06-15T21:07:14.103Z

## Task workflow update - 2026-06-15T21:13:21.210Z
- Summary: MAINT-05 stage policy: this task belongs to umbrella branch `task/maint-05-cardinal-qa-test-rework`. When started and later moved to CODE-REVIEW, open the PR against that branch rather than `main`. Skip reviewer subagent and full `LLM_MODE=true castor check`; user will review manually and MAINT-05G owns final full-gate validation.
- PR base: use `prBaseBranch="task/maint-05-cardinal-qa-test-rework"` when moving this task to CODE-REVIEW.
- Review/validation exception for MAINT-05A-F: skip reviewer subagent; user reviews manually. Skip full `LLM_MODE=true castor check` until MAINT-05G. Run focused Castor validation for this stage only, especially sequential unit/integration and ParaTest commands once implemented.

## Task workflow update - 2026-06-15T21:35:09.280Z
- Summary: Policy change: main is the MAINT-05 epic/integration branch. When this stage starts and later opens a PR, target `main` rather than `task/maint-05-cardinal-qa-test-rework`. Work proceeds sequentially after MAINT-05A is merged.
- Supersedes earlier PR-base notes in this task: do NOT use `prBaseBranch="task/maint-05-cardinal-qa-test-rework"`. Target `main` for MAINT-05B.
- Sequential execution policy: do not start MAINT-05B until MAINT-05A is manually reviewed/merged to `main`, unless the user explicitly says otherwise.
- Validation/review policy remains: skip reviewer subagent and skip full `LLM_MODE=true castor check` for MAINT-05B; user reviews manually and MAINT-05G owns final full-gate validation.

## Task workflow update - 2026-06-15T22:05:20.925Z
- Moved TODO → IN-PROGRESS.
- Created branch task/maint-05b-paratest-unit-integration-runner.
- Created worktree /home/ineersa/projects/agent-core-worktrees/maint-05b-paratest-unit-integration-runner.
- Copied vendor directory into /home/ineersa/projects/agent-core-worktrees/maint-05b-paratest-unit-integration-runner.
- Copied .vera index into /home/ineersa/projects/agent-core-worktrees/maint-05b-paratest-unit-integration-runner.
- Summary: Started MAINT-05B after MAINT-05A was merged/done. Main is the MAINT-05 integration branch and PRs target main. Per user instruction for this sequence, do not run full `castor check`; use focused Castor validation for sequential unit/integration, ParaTest, deptrac, phpstan, and cs-check. Automated reviewer remains skipped; user reviews manually.

## Task workflow update - 2026-06-15T22:06:00.650Z
- Recorded fork run: ete4maha1b30
- Validation: Pending fork run `ete4maha1b30`. Expected validation if implementation succeeds: `castor list`, `castor test`, `castor test:parallel`, `castor deptrac`, `castor phpstan`, `castor cs-check`; no full `LLM_MODE=true castor check`.
- Summary: Launched implementation fork for MAINT-05B in `/home/ineersa/projects/agent-core-worktrees/maint-05b-paratest-unit-integration-runner`. Fork instructed to add/configure ParaTest if compatible, make `castor test` deterministic sequential baseline, add separate `castor test:parallel`, remove/bypass custom PHPUnit shard fan-out, update testing docs, validate with focused Castor commands only, skip reviewer and full `castor check`, commit locally, and not push/PR.

## Task workflow update - 2026-06-15T22:25:32.846Z
- Recorded fork run: 638zseoevsq9
- Validation: Parent verification after repair: `git -C /home/ineersa/projects/agent-core status --short --branch` shows `main...origin/main` with only untracked `.pi/plans/agents-subagents-implementation-plan.md`.; Parent verification after repair: task worktree is clean at `b79c4adc` on `task/maint-05b-paratest-unit-integration-runner`.; Parent verification after repair: `git -C /home/ineersa/projects/agent-core-worktrees/maint-05b-paratest-unit-integration-runner diff --stat origin/main...HEAD` shows the expected 7 MAINT-05B implementation files: `.agents/skills/testing/SKILL.md`, `.castor/phpunit.php`, `.castor/tasks.php`, `composer.json`, `composer.lock`, `tests/AGENTS.md`, `tests/paratest-bootstrap.php`.; No `git reset --hard` used. No `git clean` used. No implementation push performed. No full `castor check` run.
- Summary: Repaired misplaced MAINT-05B implementation commit. The original implementation commit `389bd08e` had landed on local `main` instead of the task worktree. Parent verified the first repair fork handoff did not match actual repository state, then performed the non-destructive repair directly: cherry-picked `389bd08e` onto `task/maint-05b-paratest-unit-integration-runner` as `b79c4adc`, reverted the misplaced local-main commit as `bfeb1a33`, preserved backup refs, and moved local `main` back to `origin/main` via `update-ref` after confirming the tree matched. No `reset --hard`, no push of implementation, no `castor check`. Current task branch contains the MAINT-05B implementation; local main is back at `origin/main` with only an unrelated untracked `.pi/plans/agents-subagents-implementation-plan.md` file present.

## Task workflow update - 2026-06-15T22:33:53.865Z
- Moved IN-PROGRESS → CODE-REVIEW.
- Pushed task/maint-05b-paratest-unit-integration-runner to origin.
- branch 'task/maint-05b-paratest-unit-integration-runner' set up to track 'origin/task/maint-05b-paratest-unit-integration-runner'.
- Created PR: https://github.com/ineersa/agent-core/pull/142
- Validation: Pre-move inspection: task worktree clean on `task/maint-05b-paratest-unit-integration-runner` at `b79c4adc`.; Pre-move diff: `git -C /home/ineersa/projects/agent-core-worktrees/maint-05b-paratest-unit-integration-runner diff --stat origin/main...HEAD` showed 7 MAINT-05B implementation files (`.agents/skills/testing/SKILL.md`, `.castor/phpunit.php`, `.castor/tasks.php`, `composer.json`, `composer.lock`, `tests/AGENTS.md`, `tests/paratest-bootstrap.php`).; Skipped reviewer subagent per user MAINT-05A-F policy.; Skipped full `LLM_MODE=true castor check` per user MAINT-05A-F policy / MAINT-05G owns final full-gate validation.; Focused Castor validation (`castor list`, `castor test`, `castor test:parallel`, `castor deptrac`, `castor phpstan`, `castor cs-check`) was not run by the parent before this move; moving per explicit user request.
- Summary: Moved MAINT-05B to CODE-REVIEW per user request. Branch contains implementation commit `b79c4adc` and targets `main`. Automated reviewer and full `castor check` were skipped per MAINT-05 stage policy/user instruction. Note: focused validation was not run by the parent before this move; user explicitly requested moving now.

## Task workflow update - 2026-06-15T22:40:00.667Z
- Moved CODE-REVIEW → IN-PROGRESS.
- Validation: No validation run for this status change. User requested behavior change before review continues.
- Summary: Reopened MAINT-05B for user-requested PR feedback: `castor test:parallel` should not remain a separate maintained command; the ParaTest-powered path should become the default `castor test` behavior instead. Implementation PR #142 remains open and will be updated with a follow-up commit.

## Task workflow update - 2026-06-15T22:40:37.596Z
- Recorded fork run: 53azgrzs7ng7
- Validation: Pending fork run `53azgrzs7ng7`. Expected focused validation: `castor list` (confirm no `test:parallel` command), `castor test` if feasible, `castor phpstan`, `castor cs-check`; no full `LLM_MODE=true castor check`.
- Summary: Launched follow-up implementation fork for user feedback: remove separate `castor test:parallel` public command and make its ParaTest-powered behavior the default `castor test`. Fork has strict cwd/branch guard for the MAINT-05B worktree to avoid another accidental main commit, must not push, must not use `reset --hard`, and must not run full `castor check`.

## Task workflow update - 2026-06-15T22:46:18.727Z
- Recorded fork run: 53azgrzs7ng7
- Validation: Fork guard passed: cwd/root were `/home/ineersa/projects/agent-core-worktrees/maint-05b-paratest-unit-integration-runner`, branch `task/maint-05b-paratest-unit-integration-runner`.; Fork read mandatory docs: `.agents/skills/testing/SKILL.md`, `tests/AGENTS.md`, `.agents/skills/task-workflow/SKILL.md`, `.agents/skills/castor/SKILL.md`.; Fork validation: `castor list` passed; `test` appears with ParaTest default and `test:parallel` no longer appears.; Fork validation: grep for `test:parallel` / `test_parallel` in `.castor/`, `.agents/`, and `tests/AGENTS.md` found no matches.; Fork validation: `castor cs-check` passed after `castor cs-fix`.; Fork validation: `castor phpstan` passed with 0 errors.; Fork validation: `castor deptrac` passed with 0 violations.; Fork validation: `castor test --filter=ResultConverterTest` passed: 23 tests, 86 assertions, 0.6s.; Fork validation: `castor test` passed via ParaTest: 2514 tests, 7318 assertions, 34.1s, 16 processes.; Skipped full `LLM_MODE=true castor check` per MAINT-05A-F policy/user instruction.
- Summary: Follow-up PR feedback implemented by fork `53azgrzs7ng7` in MAINT-05B worktree. Separate public `castor test:parallel` command was removed; ParaTest-powered behavior is now the default `castor test` path. Filtered `castor test --filter=...` remains sequential/internal. Docs in `.agents/skills/testing/SKILL.md` and `tests/AGENTS.md` were updated to document the single command. Commit: `c5a3b5bd` (`MAINT-05B PR feedback: merge test:parallel into default castor test`). Worktree clean; not pushed by fork.

## Task workflow update - 2026-06-15T22:46:35.814Z
- Moved IN-PROGRESS → CODE-REVIEW.
- Pushed task/maint-05b-paratest-unit-integration-runner to origin.
- branch 'task/maint-05b-paratest-unit-integration-runner' set up to track 'origin/task/maint-05b-paratest-unit-integration-runner'.
- PR already exists: https://github.com/ineersa/agent-core/pull/142
- Validation: Pre-move inspection: task worktree clean on `task/maint-05b-paratest-unit-integration-runner` at `c5a3b5bd`.; Fork validation: `castor list` passed and `test:parallel` no longer appears.; Fork validation: grep for `test:parallel` / `test_parallel` in `.castor/`, `.agents/`, and `tests/AGENTS.md` found no matches.; Fork validation: `castor test` passed via ParaTest: 2514 tests, 7318 assertions, 34.1s, 16 processes.; Fork validation: `castor test --filter=ResultConverterTest` passed: 23 tests, 86 assertions, 0.6s.; Fork validation: `castor deptrac` passed with 0 violations.; Fork validation: `castor phpstan` passed with 0 errors.; Fork validation: `castor cs-check` passed after `castor cs-fix`.; Skipped reviewer subagent per user MAINT-05A-F policy.; Skipped full `LLM_MODE=true castor check` per user MAINT-05A-F policy / MAINT-05G owns final full-gate validation.
- Summary: Moved MAINT-05B back to CODE-REVIEW after applying review feedback. Branch now includes follow-up commit `c5a3b5bd` making ParaTest the default `castor test` path and removing public `castor test:parallel`. Existing PR #142 was updated by pushing the branch. Automated reviewer and full `castor check` were skipped per MAINT-05 stage policy/user instruction.
