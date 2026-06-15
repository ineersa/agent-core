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
Fork run: a4we25kl1nqt
PR URL:
PR Status:
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
