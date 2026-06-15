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
Status: TODO
Branch:
Worktree:
Fork run:
PR URL:
PR Status:
Started:
Completed:

## Work log
- Created: 2026-06-15T21:07:14.103Z
