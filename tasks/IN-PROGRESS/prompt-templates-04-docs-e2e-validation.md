# PT-04 Prompt templates docs, E2E smoke, and final validation

## Goal
Reference plan: `.pi/plans/prompt-templates-implementation-plan.md`.

Scope:
- Depends on PT-01, PT-02, and PT-03.
- Add/finish user-facing prompt-template documentation and settings docs.
- Add product-level integration or E2E coverage that exercises a real template invocation through the implemented flow where practical.
- Perform final validation of the feature as a whole.

This is intentionally last; docs can be drafted earlier, but final smoke/validation waits until runtime and TUI integration have landed.

## Acceptance criteria
- `docs/prompt-templates.md` documents Hatfield locations, top-level `prompts: []`, lowercase filename canonicalization, placeholder syntax, non-recursive discovery, first-wins collisions, CLI flags, ignored `argument-hint`, and non-MVP package/extension support.
- `docs/settings.md` and `.hatfield/settings.yaml` example content stay in sync for the `prompts: []` key.
- Integration/E2E coverage or documented smoke steps verify `/template args` expands through the final runtime/TUI path and transcript follows normal expanded prompt projection.
- No docs claim support for `prompts.paths`, `prompts.enabled`, `-np`, `argument-hint`, package manifests, extension-provided templates, or raw `/template args` transcript preservation.
- `LLM_MODE=true castor check` passes on the integrated feature; if prerequisites are unavailable, keep the task IN-PROGRESS and record the blocker rather than moving to CODE-REVIEW.
- Task metadata records final validation results and any deferred follow-ups discovered during smoke testing.

## Workflow metadata
Status: IN-PROGRESS
Branch: task/prompt-templates-04-docs-e2e-validation
Worktree: /home/ineersa/projects/agent-core-worktrees/prompt-templates-04-docs-e2e-validation
Fork run: ep1bvr5s2nxu
PR URL: https://github.com/ineersa/agent-core/pull/139
PR Status: open
Started: 2026-06-15T00:19:50.699Z
Completed:

## Work log
- Created: 2026-06-09T00:10:20.146Z

## Task workflow update - 2026-06-15T00:19:50.699Z
- Moved TODO → IN-PROGRESS.
- Created branch task/prompt-templates-04-docs-e2e-validation.
- Created worktree /home/ineersa/projects/agent-core-worktrees/prompt-templates-04-docs-e2e-validation.
- Copied vendor directory into /home/ineersa/projects/agent-core-worktrees/prompt-templates-04-docs-e2e-validation.
- Copied .vera index into /home/ineersa/projects/agent-core-worktrees/prompt-templates-04-docs-e2e-validation.

## Task workflow update - 2026-06-15T00:25:55.591Z
- Recorded fork run: a4we25kl1nqt
- Summary: Started PT-04 implementation on worktree /home/ineersa/projects/agent-core-worktrees/prompt-templates-04-docs-e2e-validation. Loaded task-workflow/testing docs, read PT-04 task, plan sections, current settings docs, .hatfield prompt comments, existing PromptTemplateSlashCommandE2ETest, and controller E2E base. Launched implementation fork to create docs/prompt-templates.md, update settings docs/comments, add/strengthen product-level prompt-template E2E coverage, run Castor validation, and commit locally without pushing or moving to code review.

## Task workflow update - 2026-06-15T00:48:22.727Z
- Recorded fork run: a4we25kl1nqt
- Validation: Verified git status clean in PT-04 worktree.; Verified commit chain exists: 2a754295 PT-04 docs/E2E, 0d79f261 controller E2E fix, 86d85cbe/ed32f00d/6a91f060 Castor cleanup/timeout fixes.; Verified diff stat: .castor/tasks.php, .hatfield/settings.yaml, docs/prompt-templates.md, docs/settings.md, tests/CodingAgent/Runtime/Controller/E2E/PromptTemplateControllerE2eTest.php.; Fork validation reported: castor test --filter=PromptTemplate OK (116 tests, 234 assertions, 2 skipped); castor test:llm-real --filter=PromptTemplate OK (2 tests, 13 assertions); castor test:tui OK (21 tests, 66 assertions); castor test OK (2505 tests, 0 failures); castor deptrac OK; castor phpstan OK; castor cs-check OK; castor test:timeout-hardstop OK (4/4 smoke tests); LLM_MODE=true castor check green twice, with one later unrelated pre-existing test:tui-1 flake on TuiAgentSmokeTest::testTypePromptAndVerifyTranscriptBlocks.
- Summary: Implementation fork completed and was verified. HEAD is 6a91f060 on branch task/prompt-templates-04-docs-e2e-validation, worktree clean. Diff origin/main...HEAD: 5 files changed, 631 insertions, 16 deletions. Expected PT-04 artifacts are present: new docs/prompt-templates.md, docs/settings.md update, .hatfield/settings.yaml prompt comments update, and new tests/CodingAgent/Runtime/Controller/E2E/PromptTemplateControllerE2eTest.php. Existing real TmuxHarness TUI proof remains in tests/Tui/E2E/PromptTemplateSlashCommandE2ETest.php with #[Group('tui-e2e')]. New controller E2E has #[Group('llm-real')] and covers auto-discovered /review expansion plus --no-prompt-templates raw passthrough. Fork also added Castor cleanup correctness fixes and test:llm-real timeout bump discovered during validation. Remaining note from fork: tmux-spawned agent processes are not descendants of PHPUnit/Castor, so per-step descendant cleanup cannot kill them; startup cleanup catches stale current-root workers on next run.
- Verified docs/prompt-templates.md exists and prompt-template controller E2E exists.
- Verified existing TmuxHarness prompt-template TUI E2E remains present with tui-e2e group.
- Checked docs for forbidden positive claims around prompts.paths/prompts.enabled/-np/argument-hint/package/extension support; only found correct no -np shortcut wording.

## Task workflow update - 2026-06-15T00:50:52.793Z
- Recorded fork run: t5r3zxymllu0
- Validation: Loaded testing skill and tests/AGENTS.md before test/process debugging.; Killed scoped stale PHAR messenger consumers via SIGTERM/SIGKILL; post-kill scan found zero remaining scoped PHAR messenger:consume workers.; Read latest PT-04 var/reports/check-test:tui-1.log: TuiAgentSmokeTest::testTypePromptAndVerifyTranscriptBlocks failed at footer cost assertion while UI still showed Working.
- Summary: Urgent stabilization started after user reported recurring test failures and orphaned processes. Parent investigation found and killed 10 scoped orphaned PHAR messenger:consume workers with deleted CWDs: 5 from PT-04 controller E2E and 5 from backlog worktree. Latest PT-04 failure is TuiAgentSmokeTest::testTypePromptAndVerifyTranscriptBlocks asserting non-zero footer cost while the capture still showed `◐ Working...`, i.e. a test race before runtime usage/cost projection settled. Process analysis showed Castor step subprocess uses setsid, but GNU timeout/controller children can run in a different PGID under the same SID; existing cleanup that kills only the original PGID can miss those workers. Launched fork t5r3zxymllu0 to fix Castor session-wide per-step cleanup and harden the TUI smoke cost assertion wait, then validate and commit locally.
- Fork t5r3zxymllu0 instructed to read testing docs, implement session-wide Castor cleanup (same SID, not only original PGID), add/extend timeout-hardstop smoke proof, fix TuiAgentSmokeTest race by waiting for settled state before cost assertion, run Castor validation, scan for stale workers, and commit locally without push/task move.

## Task workflow update - 2026-06-15T00:59:39.461Z
- Recorded fork run: t5r3zxymllu0
- Validation: Verified git status clean on PT-04 worktree at 4bbee309.; Verified expected code landmarks exist: .castor/tasks.php::_collect_session_pids, .castor/tasks.php::_reap_session, and settled-state wait in tests/Tui/E2E/TuiAgentSmokeTest.php.; Fork validation reported: castor test:timeout-hardstop OK (5/5 proofs); LLM_MODE=true castor test:tui --filter=TuiAgentSmokeTest OK (21 tests, 66 assertions, 81.5s); castor test --filter=PromptTemplate OK (116 tests, 234 assertions, 2 skipped); castor deptrac OK; castor phpstan OK; castor cs-check OK; LLM_MODE=true castor check OK (14/14 green, 375.9s); post-check ps scan found zero stale workers.; Parent post-fork scan for scoped `/home/ineersa/projects/agent-core*/var/tmp/phar/hatfield.phar messenger:consume` workers found none.
- Summary: Stabilization fork completed successfully. Verified worktree clean at HEAD 4bbee309 (`fix: session-scoped process cleanup for Castor runner, fix TUI cost assertion race`). Diff origin/main...HEAD now includes 6 files changed, 856 insertions, 57 deletions. New stabilization changes add session-scoped Castor cleanup helpers `_collect_session_pids()` and `_reap_session()` and integrate SID cleanup into Castor step lifecycle so separate-PGID grandchildren in the same session are reaped; existing PGID/descendant cleanup remains as belt-and-suspenders. TuiAgentSmokeTest::testTypePromptAndVerifyTranscriptBlocks now waits for settled state (Working/Processing gone) before asserting footer cost and skips cost assertion for error-block paths. Post-fork scan found zero scoped stale PHAR messenger workers alive.
- Accepted fork handoff as valid for stabilization: fork explicitly read testing skill and tests/AGENTS.md and used Castor for QA.
- Noted remaining known limitation from fork: tmux-spawned agent processes are children of tmux server/session rather than Castor/PHPUnit session, so session/PG cleanup cannot reap them at per-step completion; startup cleanup remains the safety net for stale current-root workers.

## Task workflow update - 2026-06-15T01:16:31.460Z
- Recorded fork run: rxxv7dukf4rj
- Summary: Reviewer subagent returned APPROVE WITH SUGGESTIONS for PT-04 at HEAD 4bbee309. No blockers; reviewer explicitly confirmed TUI proof requirement is satisfied. Actionable findings: fix false shell-style quote parsing docs example; correct single-pass docs caveat for slice-like `${@:N}` argument values; remove/fix false-positive Castor Test E for separate-SID detached children; clarify description fallback ellipsis wording; add comment for test:llm-real 120s timeout bump; comment why TuiAgentSmokeTest success flag is based on pre-settle capture. Launched fork rxxv7dukf4rj to address these suggestions, run focused Castor validation, scan for stale workers, and commit locally without push/task move.

## Task workflow update - 2026-06-15T01:55:49.724Z
- Recorded fork run: zcxj1b81hgpo
- Validation: Loaded testing skill, task-workflow skill, and tests/AGENTS.md before QA/review workflow.; Reviewer subagent final pass at HEAD 756e370a: APPROVED; no blockers; TUI proof satisfied.; castor test: OK (151.4s; agent-core 293/1271, coding-agent shards OK, tui 689/1759, platform 54/221).; castor deptrac: OK (0 violations, 0 errors).; castor phpstan: OK (No errors).; castor cs-check: OK (0 files fixed).; LLM_MODE=true castor test:tui first local run failed once on TuiStartupSnapshotTest blank pane waiting 5s for logo; no PT-04 stale PHAR workers remained; fork o228a40asx8d reran focused startup tests and found no code change needed.; Cleared stale PHAR messenger consumers before retry per AGENTS.md; LLM_MODE=true castor test:tui retry: OK (21 tests, 66 assertions, 101.8s).
- Summary: PT-04 final review fixes completed at commit 756e370a. Reviewer final pass returned APPROVED with no blockers and TUI proof satisfied. Review-fix commits addressed docs quote parsing, single-pass placeholder wording, description fallback wording, Castor timeout-hardstop Test E false-positive removal/commenting, test:llm-real timeout comment, and TuiAgentSmokeTest pre-settle success flag comment. TuiStartupSnapshotTest blank-pane failure was investigated by fork o228a40asx8d and classified as one-off environmental anomaly; no code change needed; focused startup test passed in fork. Branch worktree clean and ready for CODE-REVIEW gate.
Castor Check Status: passed
Castor Check Commit: 756e370a640cd91520f3eb239b0350526c17a3c2
Castor Check Command: LLM_MODE=true castor check
Castor Check Timeout: 600s
Castor Check Completed: 2026-06-15T01:57:12.003Z
Castor Check Output SHA256: 8bcc69f1599fe89f21903d661be076044838dd11bed6f30376a4319e355bd333

## Task workflow update - 2026-06-15T01:57:15.565Z
- Moved IN-PROGRESS → CODE-REVIEW.
- Castor quality gate passed (600s timeout). Commit: 756e370a640c.
- Pushed task/prompt-templates-04-docs-e2e-validation to origin.
- branch 'task/prompt-templates-04-docs-e2e-validation' set up to track 'origin/task/prompt-templates-04-docs-e2e-validation'.
- Created PR: https://github.com/ineersa/agent-core/pull/139

## Task workflow update - 2026-06-15T02:03:50.254Z
- Moved CODE-REVIEW → IN-PROGRESS.
- Summary: PR #139 review comments from owner: delete PromptTemplateControllerE2eTest because it does not justify the long test timeout increase; rollback out-of-scope TuiAgentSmokeTest changes. Reopening task for review iteration.

## Task workflow update - 2026-06-15T02:08:17.236Z
- Recorded fork run: ep1bvr5s2nxu
- Validation: Fork ep1bvr5s2nxu loaded testing skill and tests/AGENTS.md before QA.; castor test:timeout-hardstop: OK (4/4 proofs).; castor test: OK (7 suites, 205.9s; test-tui-suite 689 tests/1759 assertions).; castor deptrac: OK (0 violations, 0 errors).; castor phpstan: OK (0 errors).; castor cs-check: OK (0 fixes).; Stale worker scan: zero PT-04 worktree PHAR consumers; unrelated backlog worktree consumers observed and left untouched by fork.
- Summary: Addressed PR #139 owner comments at commit cbe5f343: deleted PromptTemplateControllerE2eTest.php, restored TuiAgentSmokeTest.php to origin/main exactly, and reverted the test:llm-real timeout bump/comment from 120s back to 60s. Final PR diff is now 4 files only: .castor/tasks.php, .hatfield/settings.yaml, docs/prompt-templates.md, docs/settings.md.
