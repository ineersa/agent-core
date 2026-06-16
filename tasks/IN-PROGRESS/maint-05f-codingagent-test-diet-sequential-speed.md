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
Fork run: 0lgasra9juhl
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

## Task workflow update - 2026-06-16T01:13:17.612Z
- Recorded fork run: kgk9vy2znwf5
- Validation: Pending fork `kgk9vy2znwf5`: expected validation includes `castor list`, focused `castor test --filter=...` for modified tests, sequential CodingAgent suite via Castor (for example `castor test --suite=coding-agent --sequential` if that option is added), default/ParaTest `castor test`, `castor deptrac`, `castor phpstan`, and `castor cs-check`. Full `LLM_MODE=true castor check` remains skipped per MAINT-05A-F policy.
- Summary: Launched implementation fork for MAINT-05F in `/home/ineersa/projects/agent-core-worktrees/maint-05f-codingagent-test-diet-sequential-speed`. Fork instructed to audit CodingAgent test cost centers, add Castor `test` options for sequential/suite timing if needed (without restoring separate `test:parallel` command), reduce/delete low-value tests, consolidate over-fragmented SafeGuard/runtime/path tests where safe, preserve behavior-level coverage and DB/container rules, then measure before/after counts/timings. Strict git safety rules added after earlier MAINT-05 worktree issues; commit locally only, no push/PR, no full `LLM_MODE=true castor check`, no reviewer.

## Task workflow update - 2026-06-16T01:34:57.483Z
- Recorded fork run: kgk9vy2znwf5
- Validation: Initial fork validation: guard passed in `/home/ineersa/projects/agent-core-worktrees/maint-05f-codingagent-test-diet-sequential-speed` on branch `task/maint-05f-codingagent-test-diet-sequential-speed`.; Initial fork read mandatory docs: `AGENTS.md`, `.agents/skills/testing/SKILL.md`, `tests/AGENTS.md`, `.agents/skills/task-workflow/SKILL.md`, `.agents/skills/castor/SKILL.md`, `phpunit.xml.dist`, `.castor/phpunit.php`.; Initial fork focused tests: `castor test --filter="SafeGuardCommandMatcherTest|SafeGuardPathMatcherTest|RuntimeEventTypeTest"` passed: 107 tests, 410 assertions, 0.7s.; Initial fork focused tests: `castor test --filter="TranscriptProjectorTest"` passed: 69 tests, 253 assertions, 0.7s.; Initial fork sequential CodingAgent baseline after diet: `castor test --suite=coding-agent --sequential` reported 1472 tests, 4033 assertions, 4 skipped, ~131.9s; runtime target not achieved due to kernel boot model.; Initial fork full default ParaTest: `castor test` passed: 2524 tests, 7330 assertions, 38.1s.; Initial fork static validation: `castor deptrac` 0 violations/errors; `castor phpstan` 0 errors/file_errors; `castor cs-check` 0 fixable files; `castor list` confirmed new `test --suite/--sequential` options.; Full `LLM_MODE=true castor check` skipped per MAINT-05A-F policy.
- Summary: Initial MAINT-05F fork completed and committed `af67a5003` on the task branch. It added Castor `test` options `--suite` and `--sequential` for targeted measurement, removed low-value PHP-intrinsic enum tests, consolidated SafeGuard command/path matcher tests into data-provider patterns, and consolidated TranscriptProjector no-op edge cases. Net change: 7 files, 165 insertions, 430 deletions (-265 lines). Behavior-level coverage preserved; no production code changed. Sequential CodingAgent runtime remained ~132s, with the fork identifying per-method `IsolatedKernelTestCase` kernel boot as the dominant structural blocker. User has now asked to continue in the same task and try optimizing this blocker, noting that class-level isolated dirs should be safe because ParaTest runs a test class inside one worker/process.

## Task workflow update - 2026-06-16T01:36:11.103Z
- Recorded fork run: 0lgasra9juhl
- Validation: Pending fork `0lgasra9juhl`: expected validation includes focused Castor run for all `IsolatedKernelTestCase` subclasses, `castor test --suite=coding-agent --sequential` runtime measurement, default `castor test`, `castor deptrac`, `castor phpstan`, `castor cs-check`, and `castor list`. Full `LLM_MODE=true castor check` remains skipped per MAINT-05A-F policy.
- Summary: Launched follow-up fork to try the user-requested structural optimization in the same MAINT-05F task: convert `IsolatedKernelTestCase` from per-method isolated cwd/kernel boot to per-class isolated cwd/kernel boot if feasible. User's hypothesis: the isolated directory primarily exists to keep parallel workers/classes separate, and ParaTest runs a whole test class inside one worker/process, so class-scoped cwd/kernel should be safe and should dramatically improve sequential runtime. Fork must preserve DB transaction rollback, avoid per-test kernel shutdown, clear EM state without closing the shared container, update stale docs/comments, measure sequential CodingAgent runtime against the ~131.9s post-diet baseline, and commit locally only if validation passes.
