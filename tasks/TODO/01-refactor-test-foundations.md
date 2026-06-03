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
Status: TODO
Branch:
Worktree:
Fork run:
PR URL:
PR Status:
Started:
Completed:

## Work log
- Created: 2026-06-03T00:31:43.125Z
