# MAINT-05A Castor command matrix and modular QA foundation

## Goal
## Context

First stage of the cardinal QA/test rework. This task does not try to fix all tests. It creates the maintainable Castor structure and explicit command matrix that later stages will plug into.

Current problem:
- `.castor/tasks.php` and `.castor/helpers.php` are giant procedural catch-all files.
- Models and humans struggle to safely edit Castor because unrelated concerns are mixed together.
- The default QA gate is built around fragile many-step custom fan-out.

Goal:
- Preserve Castor as the single project tooling entrypoint.
- Make Castor a thin task layer over focused, named components/files.
- Define the new command matrix before implementing ParaTest/replay/TUI changes.

Required reading before implementation:
- `.agents/skills/testing/SKILL.md`
- `tests/AGENTS.md`
- `.agents/skills/task-workflow/SKILL.md`
- `.agents/skills/castor/SKILL.md`

## Desired command matrix draft

Names can be adjusted during implementation, but the concepts must remain:

- `castor test` — deterministic sequential unit/integration baseline.
- `castor test:parallel` — ParaTest-powered unit/integration acceleration, introduced in MAINT-05B.
- `castor test:e2e` — deterministic replay/controller/runtime E2E, introduced in MAINT-05D.
- `castor test:tui` — serial journey-based TUI E2E, introduced in MAINT-05E.
- `castor test:live-llm` — opt-in live llama.cpp/provider smoke, introduced in MAINT-05C/05D.
- `castor check` — default deterministic quality gate, no live LLM dependency.
- `castor check:parallel` or equivalent — optional coarse-lane parallel gate, safe lanes only.

## Implementation shape

Split Castor by responsibility. Suggested structure, adjust if a cleaner Castor-native pattern exists:

- `.castor/tasks.php` — only public Castor task entrypoints/imports.
- `.castor/qa.php` — QA/check task definitions and command matrix.
- `.castor/phpunit.php` or `.castor/testing.php` — PHPUnit/ParaTest runner helpers.
- `.castor/e2e.php` — E2E/TUI/live/replay task definitions.
- `.castor/phar.php` — PHAR build/ensure/smoke helpers.
- `.castor/process.php` — process supervision primitives.
- `.castor/reports.php` — incremental logging/report formatting.
- `.castor/env.php` — env/preflight checks.
- `.castor/cleanup.php` — cleanup task helpers.

If PHP classes/namespaces make this safer than function files, introduce them under `.castor/src/` or another Castor-loaded location with clear names.

## Acceptance criteria
- Castor task files are split by responsibility; `.castor/tasks.php` and `.castor/helpers.php` no longer remain monolithic catch-all implementations for QA/process/PHAR/testing concerns.
- The new QA command matrix is documented in code comments and in the testing docs/skill as appropriate, even if later stages initially implement some commands as placeholders or aliases.
- Existing public Castor commands needed by current workflow still work or intentionally fail with clear guidance when deferred to a later MAINT-05 stage.
- No default QA command depends on live llama.cpp as a design requirement after this task's command matrix refactor, even if replay implementation lands later.
- Incremental report/logging primitives are introduced or isolated so later tasks can stop relying on in-memory output buffers.
- Obsolete code is not fully removed unless safe, but obvious dead/unused helpers discovered during the split are deleted rather than copied forward.
- Validation uses Castor only: at minimum `castor list`, `castor deptrac`, `castor phpstan`, `castor cs-check`, and the safest relevant existing test command available after the refactor.
- Task handoff records the new Castor file map and which later MAINT-05 tasks own remaining TODOs.

## Workflow metadata
Status: IN-PROGRESS
Branch: task/maint-05a-castor-command-matrix-modular-foundation
Worktree: /home/ineersa/projects/agent-core-worktrees/maint-05a-castor-command-matrix-modular-foundation
Fork run: srs4zqemqavs
PR URL:
PR Status:
Started: 2026-06-15T21:12:40.642Z
Completed:

## Work log
- Created: 2026-06-15T21:07:01.196Z

## Task workflow update - 2026-06-15T21:12:40.642Z
- Moved TODO → IN-PROGRESS.
- Created branch task/maint-05a-castor-command-matrix-modular-foundation.
- Created worktree /home/ineersa/projects/agent-core-worktrees/maint-05a-castor-command-matrix-modular-foundation.
- Copied vendor directory into /home/ineersa/projects/agent-core-worktrees/maint-05a-castor-command-matrix-modular-foundation.
- Copied .vera index into /home/ineersa/projects/agent-core-worktrees/maint-05a-castor-command-matrix-modular-foundation.
- Summary: Started MAINT-05A implementation stage. This task is part of the MAINT-05 umbrella/epic branch strategy. When ready for PR, target/base the PR against `task/maint-05-cardinal-qa-test-rework` instead of `main`. Per user direction for the staged MAINT-05 series: skip reviewer subagent and skip full `LLM_MODE=true castor check` until MAINT-05G; user will review manually. Use focused Castor validation appropriate to this stage.

## Task workflow update - 2026-06-15T21:13:14.549Z
- Summary: MAINT-05 stage policy: this task belongs to umbrella branch `task/maint-05-cardinal-qa-test-rework`. When ready, open the PR against that branch rather than `main`. Skip reviewer subagent and full `LLM_MODE=true castor check`; user will review manually and MAINT-05G owns final full-gate validation.
- PR base: use `prBaseBranch="task/maint-05-cardinal-qa-test-rework"` when moving this task to CODE-REVIEW.
- Review/validation exception for MAINT-05A-F: skip reviewer subagent; user reviews manually. Skip full `LLM_MODE=true castor check` until MAINT-05G. Run focused Castor validation for this stage only, e.g. `castor list` and the relevant deterministic/static commands that remain safe after the Castor refactor.

## Task workflow update - 2026-06-15T21:14:53.772Z
- Recorded fork run: biw2mxyude82
- Summary: Launched implementation fork for MAINT-05A on `/home/ineersa/projects/agent-core-worktrees/maint-05a-castor-command-matrix-modular-foundation`. Fork instructed to implement only the Castor command matrix/modular foundation stage, commit changes locally, not push/PR, skip reviewer and full `LLM_MODE=true castor check`, and use focused Castor validation only.

## Task workflow update - 2026-06-15T21:33:56.415Z
- Recorded fork run: biw2mxyude82
- Validation: Fork reported mandatory reads completed: `.agents/skills/testing/SKILL.md`, `tests/AGENTS.md`, `.agents/skills/task-workflow/SKILL.md`, `.agents/skills/castor/SKILL.md`.; Fork validation: `castor list` — all 33 tasks discovered with correct namespacing.; Fork validation: `castor deptrac` — 0 violations.; Fork validation: `castor phpstan` — 0 errors after `castor phpstan:baseline` regeneration.; Fork validation: `castor cs-check` — 0 files with issues after `castor cs-fix`.; Not run by design per user/stage policy: `LLM_MODE=true castor check`, reviewer subagent, live LLM tests, full test suite.; Parent verification: `git -C /home/ineersa/projects/agent-core-worktrees/maint-05a-castor-command-matrix-modular-foundation status --short --branch` clean on task branch.; Parent verification: `git diff --stat task/maint-05-cardinal-qa-test-rework...HEAD` shows 13 files changed: `.castor/{cleanup,e2e,env,logs,phar,phpunit,process,run,shared,tasks,tools}.php`, `castor.php`, `phpstan-baseline.neon`.
- Summary: Implementation fork completed MAINT-05A. Commit `0c075525` on `task/maint-05a-castor-command-matrix-modular-foundation` splits the monolithic Castor task system into focused responsibility files while preserving all public Castor task names. Parent verification: worktree is clean; diff against `task/maint-05-cardinal-qa-test-rework` shows expected Castor-only split plus `phpstan-baseline.neon` regeneration; no implementation outside MAINT-05A scope was reported. PR should target `task/maint-05-cardinal-qa-test-rework` when moved to review/manual PR flow.

## Task workflow update - 2026-06-15T21:35:02.701Z
- Summary: Policy change: this stage should now target `main`, not the MAINT-05 task branch. Main is the MAINT-05 epic/integration branch. Work proceeds sequentially; no parallel MAINT-05 stage branches.
- Supersedes earlier PR-base notes in this task: do NOT use `prBaseBranch="task/maint-05-cardinal-qa-test-rework"`. If/when creating a PR for MAINT-05A, target `main` (the default base).
- Sequential execution policy: finish/manual-review/merge this stage into `main` before starting MAINT-05B unless the user explicitly says otherwise.
- Validation/review policy remains: skip reviewer subagent and skip full `LLM_MODE=true castor check` for MAINT-05A; user reviews manually and MAINT-05G owns final full-gate validation.

## Task workflow update - 2026-06-15T21:36:14.714Z
- Validation: Parent action: `git -C /home/ineersa/projects/agent-core-worktrees/maint-05a-castor-command-matrix-modular-foundation rebase main` — succeeded.; Parent verification: MAINT-05A worktree clean after rebase.; Parent verification: `git diff --stat main...HEAD` still shows the same 13 implementation files changed: `.castor/{cleanup,e2e,env,logs,phar,phpunit,process,run,shared,tasks,tools}.php`, `castor.php`, `phpstan-baseline.neon`.
- Summary: Rebased MAINT-05A implementation branch onto current `main` after policy change that main is the MAINT-05 integration branch. Implementation commit is now `a65d30bb` (rebased from original `0c075525`). Diff against main remains the Castor modular split only.

## Task workflow update - 2026-06-15T21:41:23.735Z
- Recorded fork run: srs4zqemqavs
- Validation: Pending fork run `srs4zqemqavs`. Expected focused validation: `castor phpstan`, `castor cs-check`, optionally `castor list`; no full `LLM_MODE=true castor check`.
- Summary: Launched follow-up fork for MAINT-05A after user reported full `castor check` works except PHPStan. Fork instructed to reproduce/fix PHPStan via `castor phpstan`, and to disable the repo-local pi task workflow's automatic `LLM_MODE=true castor check` during `move_task(to="CODE-REVIEW")` by updating `.pi/extensions/task-workflow.ts` plus current workflow docs. Fork must not push/PR, must not run full `LLM_MODE=true castor check`, and must commit locally with clean worktree.

## Task workflow update - 2026-06-15T21:49:27.330Z
- Validation: castor phpstan — 0 errors, 0 file errors ✓; castor cs-check — 0 files with issues ✓; castor deptrac — 0 violations ✓; castor list — all tasks discovered ✓; Worktree clean ✓; Integration checkout (main) clean ✓; Removed from task-workflow.ts: runCastorCheckGate(), currentHead(), sha256Hex(), tailForError(), validateCastorTimeout(), CASTOR_CHECK_TIMEOUT_* constants, castorCheckTimeoutSeconds param; Updated: .agents/skills/task-workflow/SKILL.md, .pi/prompts/task-start.md, .pi/prompts/task-to-pr.md, .pi/prompts/task-explain.md, .pi/prompts/task-review-iterate.md
- Summary: Completed MAINT-05A follow-up work: (1) Verified PHPStan passes in worktree (0 errors, 0 file errors) — no PHPStan fix was needed; baseline was already regenerated. (2) Disabled automatic Castor quality gate from move_task CODE-REVIEW transition by removing runCastorCheckGate() and all gate helpers/schema/constants from .pi/extensions/task-workflow.ts, and updated all workflow docs/prompts/skills to reflect orchestrator/user responsibility for validation.
