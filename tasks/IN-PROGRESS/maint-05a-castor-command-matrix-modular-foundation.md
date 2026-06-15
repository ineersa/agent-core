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
Fork run:
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
