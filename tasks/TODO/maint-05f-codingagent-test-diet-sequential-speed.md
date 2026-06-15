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
Status: TODO
Branch:
Worktree:
Fork run:
PR URL:
PR Status:
Started:
Completed:

## Work log
- Created: 2026-06-15T21:08:09.602Z
