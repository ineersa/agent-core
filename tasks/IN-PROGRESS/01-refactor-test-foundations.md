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
Status: IN-PROGRESS
Branch: task/01-refactor-test-foundations
Worktree: /home/ineersa/projects/agent-core-worktrees/01-refactor-test-foundations
Fork run: jfz8t9x4iqzu
PR URL:
PR Status:
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
