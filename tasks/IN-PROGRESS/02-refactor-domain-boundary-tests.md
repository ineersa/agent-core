# 02-refactor-domain-boundary-tests: domain invariants and boundary coverage

## Goal
Plan: .pi/plans/architecture-refactor-plan.md
Reports: .pi/reports/tests-architecture.md, .pi/reports/agent-core-architecture.md

Add focused boundary tests for AgentCore domain value objects and contracts that are currently tested mostly through handlers. These tests should lock down invariants before pipeline refactors.

Scope:
- Add direct tests for RunState status/version/turn/step behavior, ToolCall/ToolResult invariants, model invocation DTO contracts, AgentMessage payload/content behavior, and lifecycle ordering edge cases.
- Use the builders from 01-refactor-test-foundations where available.
- Keep tests at domain boundaries; avoid duplicating handler tests or production implementation details.

## Acceptance criteria
- New tests cover core RunState, ToolCall/ToolResult, model invocation, command/message, and lifecycle contract invariants.
- Tests are pure unit tests unless they genuinely require the Symfony kernel; no standalone Doctrine DB setup is introduced.
- No production-only-for-test APIs or constructor bypass tricks are added.
- Run and report Castor validation: at minimum castor test --filter for new tests plus castor check, or exact environmental blockers.

## Workflow metadata
Status: IN-PROGRESS
Branch: task/02-refactor-domain-boundary-tests
Worktree: /home/ineersa/projects/agent-core-worktrees/02-refactor-domain-boundary-tests
Fork run:
PR URL:
PR Status:
Started: 2026-06-03T01:31:49.557Z
Completed:

## Work log
- Created: 2026-06-03T00:31:45.476Z

## Task workflow update - 2026-06-03T01:31:49.557Z
- Moved TODO → IN-PROGRESS.
- Created branch task/02-refactor-domain-boundary-tests.
- Created worktree /home/ineersa/projects/agent-core-worktrees/02-refactor-domain-boundary-tests.
- Copied vendor directory into /home/ineersa/projects/agent-core-worktrees/02-refactor-domain-boundary-tests.
- Copied .vera index into /home/ineersa/projects/agent-core-worktrees/02-refactor-domain-boundary-tests.
- Validation: Pre-claim integration checkout clean on main: `git status --short --branch` showed `## main...origin/main` with no changes before claim.; Read task file `tasks/TODO/02-refactor-domain-boundary-tests.md`.; Read referenced `.pi/plans/architecture-refactor-plan.md`, `.pi/reports/tests-architecture.md`, and `.pi/reports/agent-core-architecture.md`.
- Summary: Claiming task after inspecting the task file and referenced architecture/test reports. Task 01 is DONE, so the new builder/test isolation infrastructure is available on main. Scope is to add pure AgentCore domain boundary tests for RunState, ToolCall/ToolResult, model invocation DTOs, AgentMessage, command/message DTOs, and lifecycle edge cases without production-only test APIs or DB/kernel setup.
