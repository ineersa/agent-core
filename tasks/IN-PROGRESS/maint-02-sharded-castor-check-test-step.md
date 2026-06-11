# MAINT-02 Make castor check use sharded test runner

## Goal
Post-MAINT-01 issue: standalone `castor test` runs in ~45-51s because it uses the sharded parallel runner, but `LLM_MODE=true castor check` reports the `test` step at ~103s. Scout found `.castor/tasks.php::check()` builds the `test` step as a direct monolithic `vendor/bin/phpunit --exclude-group tui-e2e --exclude-group llm-real` command, bypassing `castor test` / `run_test_suites_parallel()`. Fix check so the normal `test` step uses the same sharded runner and per-worker DB/cache/report isolation as standalone `castor test`, while preserving top-level parallel check behavior and `test:controller`, `test:llm-real`, `test:tui` handling. Avoid pcntl_fork; use proc_open/external subprocesses only.

## Acceptance criteria
- `LLM_MODE=true castor check` test step uses the same sharded execution path as `castor test` instead of a single monolithic phpunit process.
- `castor test` remains ~45-55s and `castor test --filter=...` remains sequential.
- `LLM_MODE=true castor check` report/log output clearly shows or records the nested test shards so it is obvious they run in parallel.
- Per-worker `HATFIELD_TEST_DATABASE_PATH`, `HATFIELD_CACHE_DIR`, PHPUnit cache, JUnit/log isolation are preserved for the check test step.
- No `pcntl_fork` is introduced.
- Validation passes: `castor test`, `castor test:tui`, `castor test:llm-real`, `castor phpstan`, `castor deptrac`, `castor cs-check`, and preferably full `LLM_MODE=true castor check`.

## Workflow metadata
Status: IN-PROGRESS
Branch: task/maint-02-sharded-castor-check-test-step
Worktree: /home/ineersa/projects/agent-core-worktrees/maint-02-sharded-castor-check-test-step
Fork run:
PR URL:
PR Status:
Started: 2026-06-10T23:01:56.649Z
Completed:

## Work log
- Created: 2026-06-10T23:01:50.574Z

## Task workflow update - 2026-06-10T23:01:56.650Z
- Moved TODO → IN-PROGRESS.
- Created branch task/maint-02-sharded-castor-check-test-step.
- Created worktree /home/ineersa/projects/agent-core-worktrees/maint-02-sharded-castor-check-test-step.
- Copied vendor directory into /home/ineersa/projects/agent-core-worktrees/maint-02-sharded-castor-check-test-step.
- Copied .vera index into /home/ineersa/projects/agent-core-worktrees/maint-02-sharded-castor-check-test-step.

## Task workflow update - 2026-06-11T16:51:21.155Z
- Validation: castor test:llm-real: PASS (5 tests, 51 assertions); LLM_MODE=true timeout --kill-after=15s 140s castor check: PASS; wall time ~52s; all 13 steps OK (deptrac, 7 unit shards, test:controller, test:llm-real, test:tui, phpstan, cs-check)
- Summary: Implemented direct castor check sharding: check now launches unit shards as first-class parallel steps instead of the monolithic PHPUnit step, with per-step timeout wrapping and collision-free report names. Hardened llm-real smoke tests with target-tool event collection and precise prompts/assertions, and reduced TUI E2E waits to fail fast on exact UI proofs. Temp-0 llama.cpp validation passed; branch pushed at commit 96448f3b.
