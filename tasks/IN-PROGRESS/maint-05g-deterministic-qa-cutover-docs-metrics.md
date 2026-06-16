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
Status: IN-PROGRESS
Branch: task/maint-05g-deterministic-qa-cutover-docs-metrics
Worktree: /home/ineersa/projects/agent-core-worktrees/maint-05g-deterministic-qa-cutover-docs-metrics
Fork run: 64j0oqpro3l6
PR URL:
PR Status:
Started: 2026-06-16T02:21:32.044Z
Completed:

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
