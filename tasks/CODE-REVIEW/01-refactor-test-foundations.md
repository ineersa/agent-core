# 01-refactor-test-foundations: builders and filesystem isolation

## Goal
Plan: .pi/plans/architecture-refactor-plan.md
Reports: .pi/reports/tests-architecture.md

Establish test infrastructure before large architecture refactors. Add shared test data builders and a single filesystem isolation utility so future AgentCore/CodingAgent/TUI refactors can add focused tests without duplicating long constructors or temp-dir setup.

Scope:
- Add tests/Support/Builder builders for common domain/runtime messages and value objects.
- Extract shared test directory isolation helper usable by pure tests, KernelTestCase derivatives, controller E2E, and TUI E2E.
- Migrate a representative subset of noisy tests to prove the helpers work; do not churn the entire suite in this task.
- Do not add production APIs solely for tests.

## Acceptance criteria
- Builders exist for at least RunState, StartRun/AdvanceRun messages, ToolCall, and ToolCallResult with clear defaults and chainable overrides.
- A shared filesystem isolation helper exists and is used by at least one kernel/integration test and one E2E/test-harness path.
- Existing behavior is preserved; no production code changes are made except where unavoidable to keep tests compiling.
- Run and report Castor validation: at minimum castor test plus castor check, or exact environmental blockers.

## Workflow metadata
Status: CODE-REVIEW
Branch: task/01-refactor-test-foundations
Worktree: /home/ineersa/projects/agent-core-worktrees/01-refactor-test-foundations
Fork run: jfz8t9x4iqzu
PR URL: https://github.com/ineersa/agent-core/pull/84
PR Status: open
Started: 2026-06-03T00:45:17.864Z
Completed:

## Work log
- Created: 2026-06-03T00:31:43.125Z

## Task workflow update - 2026-06-03T00:45:17.864Z
- Moved TODO → IN-PROGRESS.
- Created branch task/01-refactor-test-foundations.
- Created worktree /home/ineersa/projects/agent-core-worktrees/01-refactor-test-foundations.
- Copied vendor directory into /home/ineersa/projects/agent-core-worktrees/01-refactor-test-foundations.
- Copied .vera index into /home/ineersa/projects/agent-core-worktrees/01-refactor-test-foundations.
- Summary: Claimed task after reading task file, architecture refactor plan, tests architecture report, and Castor skill instructions. Integration checkout was clean on main before claim.

## Task workflow update - 2026-06-03T00:49:48.470Z
- Validation: Pre-claim integration checkout clean: `git status --porcelain=v1 && git branch --show-current` output only `main` (no changed files).; Required validation for implementation: `castor test --filter=AdvanceRunHandlerTest`, `castor test --filter=StartRunHandlerTest`, `castor test --filter=ToolExecutorTest`, `castor test --filter=BgStatusToolTest`, `castor test:tui`, `castor test`, `castor check` (or exact tmux/llama.cpp blockers).
- Summary: Preparation context gathered. Worktree: /home/ineersa/projects/agent-core-worktrees/01-refactor-test-foundations. Read task, .pi/plans/architecture-refactor-plan.md, .pi/reports/tests-architecture.md, Castor/subagent skills, composer autoload, target domain DTO constructors, representative noisy tests, and filesystem isolation bases. Scout recon identified builder targets in RunState/StartRun/AdvanceRun/ToolCall/ToolCallResult and shared filesystem duplication across IsolatedKernelTestCase, BgStatusToolTest, ControllerE2eTestCase, and TUI E2E tests. Full scout artifact: /home/ineersa/.pi/agent/tmp/2026-06--115d84a6.txt.
- Claimed task and created worktree/branch via move_task.
- Ran two scout subagents for builder and filesystem-isolation reconnaissance; no code edits made by parent.

## Task workflow update - 2026-06-03T00:52:25.610Z
- Recorded fork run: jfz8t9x4iqzu
- Summary: Launched implementation fork in background on worktree /home/ineersa/projects/agent-core-worktrees/01-refactor-test-foundations with explicit plan for AgentCore test builders, shared TestDirectoryIsolation helper, representative test migrations, Castor validation, and commit. Parent will verify fork report when it returns.
- Fork jfz8t9x4iqzu launched on worktree; parent did not implement directly.

## Task workflow update - 2026-06-03T00:59:15.301Z
- Recorded fork run: jfz8t9x4iqzu
- Validation: Parent verification: `git rev-parse --verify 8d22148c^{commit}` => 8d22148c1e0951b4347d8bb60e27abd637a19a1f.; Parent verification: `git branch --show-current` in worktree => task/01-refactor-test-foundations; `git status --porcelain=v1` => clean.; Parent verification: `git diff --stat HEAD~1..HEAD` => 16 files changed, 1426 insertions(+), 345 deletions(-).; Parent verification: changed files are all under tests/; no production files changed.; Fork validation: `castor test --filter=DomainMessageBuildersTest` passed (tests=15, assertions=111).; Fork validation: `castor test --filter=TestDirectoryIsolationTest` passed (tests=9, assertions=15).; Fork validation: `castor test --filter=AdvanceRunHandlerTest` passed.; Fork validation: `castor test --filter=StartRunHandlerTest` passed.; Fork validation: `castor test --filter=ToolCallResultHandlerTest` passed (tests=1, assertions=14).; Fork validation: `castor test --filter=ToolExecutorTest` passed (tests=14, assertions=52).; Fork validation: `castor test --filter=BgStatusToolTest` passed (tests=11, assertions=25).; Fork validation: `castor test` passed (tests=1486, assertions=4399).; Fork validation: `castor check` passed all stages: deptrac, phpunit, controller E2E, real LLM E2E, TUI E2E, phpstan, cs-check.
- Summary: Fork jfz8t9x4iqzu completed successfully. Parent verification confirmed worktree branch task/01-refactor-test-foundations is clean at commit 8d22148c (`test: add builders and directory isolation helper`), commit exists, and diff matches expected task scope: 16 test files changed with new AgentCore builders, CodingAgent TestDirectoryIsolation helper, representative builder migrations, and filesystem-isolation migrations. `git diff --name-only HEAD~1..HEAD | grep -v '^tests/'` returned no production-file changes. Ready for CODE-REVIEW when the parent/user chooses to open the PR.
- Fork handoff verified by parent: commit exists, worktree clean, expected test-only diff confirmed.
Castor Check Status: passed
Castor Check Commit: 8d22148c1e0951b4347d8bb60e27abd637a19a1f
Castor Check Command: LLM_MODE=true castor check
Castor Check Timeout: 900s
Castor Check Completed: 2026-06-03T01:00:56.320Z
Castor Check Output SHA256: c603c3c01db3f2a6eeb72fcf21b403a93a7cc97262466083d303370e2ca4adc8

## Task workflow update - 2026-06-03T01:00:59.681Z
- Moved IN-PROGRESS → CODE-REVIEW.
- Castor quality gate passed (900s timeout). Commit: 8d22148c1e09.
- Pushed task/01-refactor-test-foundations to origin.
- branch 'task/01-refactor-test-foundations' set up to track 'origin/task/01-refactor-test-foundations'.
- Created PR: https://github.com/ineersa/agent-core/pull/84
- Validation: Commit verified: 8d22148c1e0951b4347d8bb60e27abd637a19a1f (`test: add builders and directory isolation helper`).; Diff verified: 16 files changed, 1426 insertions(+), 345 deletions(-), all under tests/.; Fork validation passed: focused builder/isolation/migrated tests, `castor test`, and `castor check` all successful.
- Summary: Implementation complete at commit 8d22148c. Parent verified the worktree is clean on branch task/01-refactor-test-foundations, the expected 16 test-only files changed, and no production files changed. Fork validation reported successful focused Castor tests, full `castor test`, and full `castor check`.

## Task workflow update - 2026-06-03T01:10:07.865Z
- Validation: Inspect: `git status --short --branch` => `## task/01-refactor-test-foundations...origin/task/01-refactor-test-foundations` with no changes.; Inspect: `git log --oneline --decorate -10` shows HEAD `8d22148c (HEAD -> task/01-refactor-test-foundations, origin/task/01-refactor-test-foundations) test: add builders and directory isolation helper`.; Inspect: `git diff --stat origin/main...HEAD` => 16 files changed, 1426 insertions(+), 345 deletions(-).; Reviewer subagent decision: APPROVED for current HEAD; no critical issues or request-changes findings.; `castor test` => ok (tests=1486, assertions=4399, errors=0, failures=0, skipped=0); junit=var/reports/phpunit.junit.xml.; `castor deptrac` => ok (violations=0, errors=0, uncovered=591, allowed=856).; `castor phpstan` => ok (errors=0, file_errors=0).; `castor cs-check` => ok (files_fixed=0).
- Summary: PR/code-review preparation check completed after task was already in CODE-REVIEW. Worktree `/home/ineersa/projects/agent-core-worktrees/01-refactor-test-foundations` is clean on branch `task/01-refactor-test-foundations` tracking `origin/task/01-refactor-test-foundations`; current HEAD is 8d22148c (`test: add builders and directory isolation helper`). Full diff remains 16 test-only files changed (+1426/-345). Reviewer subagent returned APPROVED for current HEAD; only non-blocking observations were noted (minor redundancy/comments/naming conventions). Focused local Castor validation passed: `castor test`, `castor deptrac`, `castor phpstan`, `castor cs-check`. Task was not moved again because it is already CODE-REVIEW with open PR https://github.com/ineersa/agent-core/pull/84 and the move_task quality gate already passed for commit 8d22148c.
- PR preparation audit rerun: inspected branch/log/diff, reviewer approved current HEAD, and fast Castor validation passed. No new commits or fixes were needed.
