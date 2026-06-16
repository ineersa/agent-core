# MAINT-05E Rework TUI E2E into replay-backed journey tests

## Goal
## Context

Fifth stage of the cardinal QA/test rework. TUI E2E should stop launching a new tmux/TUI process for every tiny assertion. Most TUI behavior should be proven by a small number of long-lived journey tests, using deterministic LLM replay where model output is needed.

Current problem:
- Too many TUI E2E tests each launch tmux and wait through startup/runtime/model paths.
- Repeated launches make tests slow, flaky, and hard to reason about.
- Some tests use fragile patterns such as `exec sleep 10`.

Dependencies:
- Prefer after MAINT-05C replay foundation.
- Some process ownership assumptions may rely on MAINT-05D.

Known entrypoints:
- `tests/Tui/E2E/TmuxHarness.php`
- `tests/Tui/E2E/TuiAgentSmokeTest.php`
- `tests/Tui/E2E/TuiStartupSnapshotTest.php`
- `tests/Tui/E2E/HotkeySmokeTest.php`
- `tests/Tui/E2E/ImmediateSubmitFeedbackTest.php`
- `tests/Tui/E2E/ShellPrefixSmokeTest.php`
- `tests/Tui/E2E/EditorBorderColorTest.php`
- `tests/Tui/E2E/ReasoningCycleTest.php`
- `tests/Tui/E2E/SessionRenameE2ETest.php`
- `tests/Tui/E2E/PromptTemplateSlashCommandE2ETest.php`

## Acceptance criteria
- Default TUI E2E is organized as a small number of journey tests that reuse a long-lived tmux/TUI session for multiple assertions.
- Separate tmux launches remain only for behavior that explicitly requires process start/end/resume/relaunch isolation.
- TUI tests that need model output use deterministic replay fixtures, not live llama.cpp, in the default suite.
- UI-only behaviors are grouped into one or a few journeys: startup layout, editor keys, hotkeys, reasoning cycling, border/status state, rename, slash commands, shell-prefix local validation where appropriate.
- Overlapping smoke tests are consolidated; assertions are preserved at behavior level but not duplicated across many process launches.
- `exec sleep 10` and similar fixed process-holding patterns are removed. Fixed sleeps are not added except where timing itself is the behavior under test.
- TmuxHarness exposes journey-friendly helpers/steps and reliable teardown; tests leave no tmux sessions or child processes behind on failure.
- The task records before/after TUI harness launch count and wall time.
- Validation uses Castor only: `castor test:tui`, relevant replay/controller tests if needed, `castor deptrac`, `castor phpstan`, `castor cs-check`, and default deterministic check if available.
- Docs/skills are updated so future TUI tests follow the journey model instead of one-harness-per-assertion.

## Workflow metadata
Status: IN-PROGRESS
Branch: task/maint-05e-tui-journey-e2e-rework
Worktree: /home/ineersa/projects/agent-core-worktrees/maint-05e-tui-journey-e2e-rework
Fork run: kczj79mfkzym
PR URL: https://github.com/ineersa/agent-core/pull/146
PR Status: open
Started: 2026-06-16T00:10:29.822Z
Completed:

## Work log
- Created: 2026-06-15T21:07:56.576Z

## Task workflow update - 2026-06-15T21:13:41.276Z
- Summary: MAINT-05 stage policy: this task belongs to umbrella branch `task/maint-05-cardinal-qa-test-rework`. When started and later moved to CODE-REVIEW, open the PR against that branch rather than `main`. Skip reviewer subagent and full `LLM_MODE=true castor check`; user will review manually and MAINT-05G owns final full-gate validation.
- PR base: use `prBaseBranch="task/maint-05-cardinal-qa-test-rework"` when moving this task to CODE-REVIEW.
- Review/validation exception for MAINT-05A-F: skip reviewer subagent; user reviews manually. Skip full `LLM_MODE=true castor check` until MAINT-05G. Run focused Castor validation for this stage only, especially journey-based `castor test:tui` or its new deterministic replay-backed replacement once implemented.

## Task workflow update - 2026-06-15T21:35:29.741Z
- Summary: Policy change: main is the MAINT-05 epic/integration branch. When this stage starts and later opens a PR, target `main` rather than `task/maint-05-cardinal-qa-test-rework`. Work proceeds sequentially after prior MAINT-05 stages are merged.
- Supersedes earlier PR-base notes in this task: do NOT use `prBaseBranch="task/maint-05-cardinal-qa-test-rework"`. Target `main` for MAINT-05E.
- Sequential execution policy: do not start MAINT-05E until prior MAINT-05 stages that it depends on are manually reviewed/merged to `main`, unless the user explicitly says otherwise.
- Validation/review policy remains: skip reviewer subagent and skip full `LLM_MODE=true castor check` for MAINT-05E; user reviews manually and MAINT-05G owns final full-gate validation.

## Task workflow update - 2026-06-16T00:10:29.822Z
- Moved TODO → IN-PROGRESS.
- Created branch task/maint-05e-tui-journey-e2e-rework.
- Created worktree /home/ineersa/projects/agent-core-worktrees/maint-05e-tui-journey-e2e-rework.
- Copied vendor directory into /home/ineersa/projects/agent-core-worktrees/maint-05e-tui-journey-e2e-rework.
- Copied .vera index into /home/ineersa/projects/agent-core-worktrees/maint-05e-tui-journey-e2e-rework.
- Summary: Started MAINT-05E after MAINT-05D was merged/done. Main remains the MAINT-05 integration branch and eventual PR targets main. Per MAINT-05A-F policy, skip automated reviewer and full `LLM_MODE=true castor check`; run focused TUI journey/replay validation only. Scope is test-infrastructure rework: default TUI E2E should be replay-backed and journey-based, with live TUI smoke remaining opt-in.

## Task workflow update - 2026-06-16T00:11:39.993Z
- Recorded fork run: vtq0frsspzl7
- Validation: Pending fork run `vtq0frsspzl7`. Expected focused validation: `castor list`, deterministic `castor test:tui --filter=<journey>` during development, final deterministic `castor test:tui`, `castor test:controller-replay` if shared replay/test env wiring changes, `castor test` if feasible, `castor deptrac`, `castor phpstan`, `castor cs-check`; no full `LLM_MODE=true castor check`; live smoke only if explicit/prereqs available.
- Summary: Launched implementation fork for MAINT-05E in `/home/ineersa/projects/agent-core-worktrees/maint-05e-tui-journey-e2e-rework`. Fork instructed to rework TUI E2E into deterministic replay-backed journey tests, reuse MAINT-05D test-layer replay DI (`APP_ENV=test`, source `bin/console`, `HATFIELD_LLM_REPLAY_FIXTURE_PATH`, no production replay code), consolidate overlapping tmux tests into fewer long-lived sessions, remove `exec sleep 10`/fixed waits, preserve live TUI smoke as opt-in, improve TmuxHarness helpers/teardown, update Castor/docs, and record launch-count/wall-time metrics. Fork has strict cwd/branch guard, must not push, must not use destructive git operations, must not run reviewer, and must not run full `LLM_MODE=true castor check`.

## Task workflow update - 2026-06-16T00:27:15.181Z
- Recorded fork run: vtq0frsspzl7
- Validation: Fork guard passed: cwd/root `/home/ineersa/projects/agent-core-worktrees/maint-05e-tui-journey-e2e-rework`, branch `task/maint-05e-tui-journey-e2e-rework`.; Fork read mandatory docs: `.agents/skills/testing/SKILL.md`, `tests/AGENTS.md`, `.agents/skills/task-workflow/SKILL.md`, `.agents/skills/castor/SKILL.md`, `docs/llm-replay.md`, `docs/tui-testing.md`, and `docs/tui-architecture.md`.; Fork validation: `php -l` on new PHP files passed.; Fork validation: `castor list` passed and shows `test:tui`, `test:tui-live`, and `test:tui-update`.; Fork validation: `castor test:tui` passed: 3 tests, 35 assertions, 10.2s, replay-backed, no live LLM.; Fork validation: `castor test` passed: 2520 tests, 7359 assertions, 37.2s.; Fork validation: `castor test:controller-replay` passed: 1 test, 14 assertions, 9.4s.; Fork validation: `castor deptrac` passed with 0 violations/errors.; Fork validation: `castor phpstan` passed with 0 errors/file_errors.; Fork validation: `castor cs-check` passed with 0 fixable files.; Skipped full `LLM_MODE=true castor check` per MAINT-05A-F policy/user instruction.; Live LLM smoke not run; llama.cpp unavailable and live smoke remains opt-in via `castor test:tui-live`.; Parent verification: worktree clean at `9b6358cf`; grep found no remaining `exec sleep 10`/`sleep 10` pattern under `tests/Tui/E2E` or `.castor`.; Metrics recorded by fork: default TUI harness launches reduced from 12 to 2, wall time from ~120-180s live to 10.2s replay, default live LLM calls from 12+ to 0, `exec sleep 10` instances from 1 to 0.
- Summary: Implementation fork completed MAINT-05E. Added replay-backed TUI journey E2E with `TuiJourneyE2eTest`, two MAINT-05C-format replay fixtures, replay-backed `TuiStartupSnapshotTest`, and Castor command restructuring so `castor test:tui` is deterministic (`tui-e2e-replay`) while `castor test:tui-live` remains opt-in live LLM. Removed `exec sleep 10`, preserved source/test DI replay architecture, and updated testing docs/skills. Commit: `9b6358cf` (`MAINT-05E: TUI journey E2E rework — replay-backed, one-session journey tests`). Parent verified worktree is clean and diff against `origin/main` shows expected 8 files.

## Task workflow update - 2026-06-16T00:27:41.072Z
- Moved IN-PROGRESS → CODE-REVIEW.
- Pushed task/maint-05e-tui-journey-e2e-rework to origin.
- branch 'task/maint-05e-tui-journey-e2e-rework' set up to track 'origin/task/maint-05e-tui-journey-e2e-rework'.
- Created PR: https://github.com/ineersa/agent-core/pull/146
- Validation: Pre-move inspection: task worktree clean on `task/maint-05e-tui-journey-e2e-rework` at `9b6358cf`.; Parent diff inspection: `git -C /home/ineersa/projects/agent-core-worktrees/maint-05e-tui-journey-e2e-rework diff --stat origin/main...HEAD` showed expected 8 files for TUI journey/replay rework.; Parent grep verification: no remaining `exec sleep 10`/`sleep 10` pattern under `tests/Tui/E2E` or `.castor`.; Fork validation: `php -l` on new PHP files passed.; Fork validation: `castor list` passed and shows `test:tui`, `test:tui-live`, and `test:tui-update`.; Fork validation: `castor test:tui` passed: 3 tests, 35 assertions, 10.2s, replay-backed, no live LLM.; Fork validation: `castor test` passed: 2520 tests, 7359 assertions, 37.2s.; Fork validation: `castor test:controller-replay` passed: 1 test, 14 assertions, 9.4s.; Fork validation: `castor deptrac` passed with 0 violations/errors.; Fork validation: `castor phpstan` passed with 0 errors/file_errors.; Fork validation: `castor cs-check` passed with 0 fixable files.; Skipped reviewer subagent per user MAINT-05A-F policy.; Skipped full `LLM_MODE=true castor check` per MAINT-05A-F policy / MAINT-05G owns final full-gate validation.; Live LLM smoke not run; llama.cpp unavailable and live smoke remains opt-in via `castor test:tui-live`.; Metrics recorded by fork: default TUI harness launches reduced from 12 to 2, wall time from ~120-180s live to 10.2s replay, default live LLM calls from 12+ to 0, `exec sleep 10` instances from 1 to 0.
- Summary: Moved MAINT-05E to CODE-REVIEW. Branch contains implementation commit `9b6358cf` with replay-backed TUI journey E2E, deterministic default `castor test:tui`, opt-in live `castor test:tui-live`, replay fixtures, removal of `exec sleep 10`, and docs updates. Automated reviewer and full `castor check` were skipped per MAINT-05 stage policy/user instruction.

## Task workflow update - 2026-06-16T00:30:01.168Z
- Moved CODE-REVIEW → IN-PROGRESS.
- Validation: User feedback: `castor test:tui-live` live-LLM tests should be removed instead of kept opt-in now that `castor check` no longer uses them.; Inspected current MAINT-05E worktree and confirmed `test:tui-live` command/docs plus legacy `#[Group('tui-e2e')]` test classes remain.
- Summary: Moved MAINT-05E back to IN-PROGRESS for user PR feedback: remove the opt-in live TUI E2E path (`castor test:tui-live` / `#[Group('tui-e2e')]`) because it is no longer part of `castor check` and the user sees no value in keeping it. Follow-up should delete the live TUI command/docs and remove or replace remaining live-only TUI E2E tests with replay-backed coverage only.

## Task workflow update - 2026-06-16T00:30:58.509Z
- Recorded fork run: riwo4uzpubae
- Validation: Pending fork run `riwo4uzpubae`. Expected validation: `castor list` with no `test:tui-live`, `castor test:tui`, focused `castor test:tui --filter=TuiJourneyE2eTest`, `castor test` if feasible, `castor deptrac`, `castor phpstan`, `castor cs-check`; no full `LLM_MODE=true castor check`; no live llama.cpp/TUI tests.
- Summary: Launched review-iteration fork for user feedback to remove the opt-in live TUI E2E path entirely. Fork instructed to delete `castor test:tui-live`, remove docs/skills references to live TUI opt-in, delete or migrate old live-only `#[Group('tui-e2e')]` TUI test classes, remove obsolete group excludes/sharding helpers if dead, keep `castor test:tui` replay-backed deterministic, and validate with Castor only.

## Task workflow update - 2026-06-16T00:38:48.431Z
- Recorded fork run: riwo4uzpubae
- Validation: Fork guard passed: cwd/root `/home/ineersa/projects/agent-core-worktrees/maint-05e-tui-journey-e2e-rework`, branch `task/maint-05e-tui-journey-e2e-rework`.; Fork read mandatory docs: `.agents/skills/testing/SKILL.md`, `tests/AGENTS.md`, `.agents/skills/task-workflow/SKILL.md`, `.agents/skills/castor/SKILL.md`, `docs/llm-replay.md`, and `docs/tui-testing.md`.; Fork validation: `php -l` on remaining TUI files passed.; Fork validation: `castor list` passed and no longer shows `test:tui-live`.; Fork validation: `castor test:tui` passed: 3 tests, 35 assertions, 14.0s.; Fork validation: `castor test` passed: 2520 tests, 7359 assertions, 28.0s.; Fork validation: `castor deptrac` passed with 0 violations/errors.; Fork validation: `castor phpstan` passed with 0 errors/file_errors.; Fork validation: `castor cs-check` passed with 0 fixable files.; Skipped full `LLM_MODE=true castor check` per MAINT-05A-F policy/user instruction.; Live LLM smoke not run; no live TUI suite remains, while real LLM smoke commands (`test:llm-real`, `test:controller`) remain.; Parent verification: worktree clean at `7cc28c2e`, branch 1 commit ahead of origin PR branch.; Parent follow-up finding: `.agents/skills/task-workflow/SKILL.md` still contains old TUI requirement text referencing real test LLM and `#[Group('tui-e2e')]`; `.agents/skills/testing/SKILL.md`/`tests/AGENTS.md` still mention `test:tui-update` in LLM preflight despite replay-backed implementation; root `AGENTS.md` still says all E2E tests use llama_cpp_test/test; fixtures under `tests/Tui/E2E/fixtures/` have `recorded_at`/`recording_source` fields even though they appear hand-authored. Additional fork needed before moving to CODE-REVIEW.
- Summary: Review-iteration fork completed user feedback to remove the opt-in live TUI E2E suite. Deleted legacy live-only TUI E2E test files, removed `castor test:tui-live`, removed obsolete TUI shard helpers, switched `castor check` TUI lane to single replay-backed `tui-e2e-replay` step, removed old `#[Group('tui-e2e')]` annotations from remaining TUI tests, and updated docs/skills. Commit: `7cc28c2e` (`MAINT-05E: Address PR feedback — remove live TUI E2E suite entirely`). Parent verification found the worktree clean and `castor test:tui-live` removed, but also found follow-up cleanup still needed before CODE-REVIEW: some active docs/comments still mention old live TUI/TUI preflight policy, and TUI replay fixtures contain misleading recorded metadata despite being synthetic/hand-authored.

## Task workflow update - 2026-06-16T00:39:52.683Z
- Recorded fork run: kczj79mfkzym
- Validation: Pending fork run `kczj79mfkzym`. Expected validation: `castor list` confirming no `test:tui-live` and real LLM commands remain, `castor test:tui`, focused `castor test:tui --filter=TuiJourneyE2eTest` if feasible, `castor test`, `castor deptrac`, `castor phpstan`, `castor cs-check`; no full `LLM_MODE=true castor check`; no live llama.cpp/TUI tests.
- Summary: Launched follow-up cleanup fork after parent verification of `riwo4uzpubae`. Required fixes: update stale active docs/skills/comments that still mention old real-LLM TUI proof or `#[Group('tui-e2e')]`, fix incorrect preflight docs for replay-backed `test:tui-update`, update root `AGENTS.md` E2E wording to distinguish replay-backed TUI/controller from live LLM smoke, and make TUI replay fixture provenance honest (synthetic markers instead of fake `recorded_at`/`recording_source`, or recorded fixtures if live recording is available). Fork must keep real LLM smoke commands (`test:llm-real`, `test:controller`, `llm:fixtures:record`) intact while ensuring no live TUI suite/`test:tui-live` remains.

## Task workflow update - 2026-06-16T00:52:16.433Z
- Recorded fork run: kczj79mfkzym
- Validation: Fork guard initially passed but fork later reported git corruption caused by `git rebase origin/main`/wrong worktree handling.; Fork validation reported: `castor list`, `castor test:tui`, `castor test`, `castor deptrac`, `castor phpstan`, `castor cs-check` passed; full `LLM_MODE=true castor check` skipped per MAINT-05 policy.; Parent repair validation: integration checkout `/home/ineersa/projects/agent-core` is clean on `main` tracking `origin/main`.; Parent repair validation: task worktree `/home/ineersa/projects/agent-core-worktrees/maint-05e-tui-journey-e2e-rework` is clean on `task/maint-05e-tui-journey-e2e-rework`, ahead of origin by 2 commits (`7cc28c2e`, `6e2f834fe`).; Parent decision: do not move to CODE-REVIEW; launch follow-up fork to do fixture recording properly through Castor and reject hand-written recording scripts.
- Summary: Fork `kczj79mfkzym` completed but is NOT accepted as final for CODE-REVIEW. It fixed stale docs and produced live-recorded TUI fixtures, but user identified that fixtures were generated through an ad-hoc curl/PHP conversion script instead of the project-supported `castor llm:fixtures:record` flow. Fork also corrupted git by switching the integration checkout/worktree branch state; parent repaired git manually without `git reset --hard`: stashed corrupted integration checkout state, moved stray nested vendor to `/tmp/agent-core-git-repair-20260615-205142/`, switched `/home/ineersa/projects/agent-core` back to `main`, and confirmed task branch remains clean in the proper worktree. Backup stash: `stash@{0}: backup corrupted integration checkout before maint-05e repair 20260615-205142`. Follow-up required: remove reliance on ad-hoc recording, make/update the Castor fixture recording command to record the TUI fixtures properly, then regenerate/verify via `castor llm:fixtures:record` only.
