# MAINT-05F CodingAgent test diet and sequential speed target

## Goal
## Context

Sixth stage of the cardinal QA/test rework. CodingAgent unit/integration tests must become fast and understandable enough that sequential runtime is reasonable. Parallelization should be optional acceleration, not a way to hide bad test structure.

Target:
- CodingAgent unit/integration suite should run sequentially in roughly under 30s on a normal dev machine, or this task must record concrete blockers and remaining cost centers.

Current scout findings:
- `tests/CodingAgent/Runtime/RuntimeEventTypeTest.php` over-tests enum/PHP intrinsic behavior.
- SafeGuard command/path matcher tests are over-fragmented into many tiny methods.
- Kernel-heavy candidates include `Config/ModelSelectionServiceTest.php`, `Session/HatfieldSessionStoreTest.php`, `Tool/BashToolTest.php`, `Tool/BackgroundProcessManagerTest.php`.
- Large audit candidates include `Runtime/Projection/TranscriptProjectorTest.php`, `Tool/ViewImageToolTest.php`, `Tool/ToolRegistryTest.php`, `Path/PathResolverTest.php`.

Rules:
- Preserve behavior-level coverage.
- Delete/reduce low-value tests that verify PHP enum mechanics, getters/setters, class existence, or exhaustive intrinsic behavior.
- Do not add production APIs solely for tests.
- DB-touching tests must still boot Symfony kernel and use the test container.

## Acceptance criteria
- CodingAgent test suite is audited with measured or clearly estimated cost centers before changes.
- Low-value tests that verify PHP enum mechanics, getters/setters, class existence, or exhaustive intrinsic behavior are removed/reduced while preserving meaningful behavior coverage.
- Over-fragmented matcher/path/config tests are consolidated into behavior-focused table/data-provider tests where appropriate.
- KernelTestCase usage is reduced to true integration/DB/container behavior. Pure logic is tested without kernel boot where feasible.
- Large test files are simplified enough that future agents can understand the behavior under test without scanning hundreds of redundant assertions.
- Sequential CodingAgent test runtime is measured after cleanup and targets roughly under 30s. If not achieved, exact remaining blockers and next candidates are recorded.
- ParaTest compatibility from MAINT-05B is preserved; the cleaned suite works both sequentially and under ParaTest where safe.
- Validation uses Castor only: sequential CodingAgent/unit integration command, ParaTest command if available, `castor deptrac`, `castor phpstan`, and `castor cs-check`.
- Task handoff includes before/after test counts, method/file reductions, runtime timings, and coverage tradeoffs.

## Workflow metadata
Status: IN-PROGRESS
Branch: task/maint-05f-codingagent-test-diet-sequential-speed
Worktree: /home/ineersa/projects/agent-core-worktrees/maint-05f-codingagent-test-diet-sequential-speed
Fork run:
PR URL:
PR Status:
Started: 2026-06-16T01:11:28.618Z
Completed:

## Work log
- Created: 2026-06-15T21:08:09.602Z

## Task workflow update - 2026-06-15T21:13:47.628Z
- Summary: MAINT-05 stage policy: this task belongs to umbrella branch `task/maint-05-cardinal-qa-test-rework`. When started and later moved to CODE-REVIEW, open the PR against that branch rather than `main`. Skip reviewer subagent and full `LLM_MODE=true castor check`; user will review manually and MAINT-05G owns final full-gate validation.
- PR base: use `prBaseBranch="task/maint-05-cardinal-qa-test-rework"` when moving this task to CODE-REVIEW.
- Review/validation exception for MAINT-05A-F: skip reviewer subagent; user reviews manually. Skip full `LLM_MODE=true castor check` until MAINT-05G. Run focused Castor validation for this stage only, especially sequential CodingAgent tests, ParaTest if available, and static checks.

## Task workflow update - 2026-06-15T21:35:36.382Z
- Summary: Policy change: main is the MAINT-05 epic/integration branch. When this stage starts and later opens a PR, target `main` rather than `task/maint-05-cardinal-qa-test-rework`. Work proceeds sequentially after prior MAINT-05 stages are merged.
- Supersedes earlier PR-base notes in this task: do NOT use `prBaseBranch="task/maint-05-cardinal-qa-test-rework"`. Target `main` for MAINT-05F.
- Sequential execution policy: do not start MAINT-05F until prior MAINT-05 stages that it depends on are manually reviewed/merged to `main`, unless the user explicitly says otherwise.
- Validation/review policy remains: skip reviewer subagent and skip full `LLM_MODE=true castor check` for MAINT-05F; user reviews manually and MAINT-05G owns final full-gate validation.

## Task workflow update - 2026-06-16T01:11:28.618Z
- Moved TODO → IN-PROGRESS.
- Created branch task/maint-05f-codingagent-test-diet-sequential-speed.
- Created worktree /home/ineersa/projects/agent-core-worktrees/maint-05f-codingagent-test-diet-sequential-speed.
- Copied vendor directory into /home/ineersa/projects/agent-core-worktrees/maint-05f-codingagent-test-diet-sequential-speed.
- Copied .vera index into /home/ineersa/projects/agent-core-worktrees/maint-05f-codingagent-test-diet-sequential-speed.
- Summary: Started MAINT-05F after MAINT-05E was merged/done. Scope: audit and reduce CodingAgent unit/integration test complexity and sequential runtime while preserving behavior-level coverage and ParaTest compatibility. Main remains the MAINT-05 integration branch and eventual PR targets main. Per MAINT-05A-F policy, skip automated reviewer and full `LLM_MODE=true castor check`; run focused Castor validation for this stage only, especially sequential/ParaTest unit-integration tests and static checks.
