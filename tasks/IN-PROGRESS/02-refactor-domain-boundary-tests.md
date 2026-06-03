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
Fork run: q97fkoum8ive
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

## Task workflow update - 2026-06-03T01:37:45.855Z
- Validation: Worktree created: /home/ineersa/projects/agent-core-worktrees/02-refactor-domain-boundary-tests on branch task/02-refactor-domain-boundary-tests.; Worktree inspected: `git status --short --branch` => `## task/02-refactor-domain-boundary-tests`; no changed files.; Scout artifact for detailed recon: /home/ineersa/.pi/agent/tmp/2026-06--2cb16335.txt.
- Summary: Preparation context gathered for implementation fork. Read the moved task file, Domain/Event/Message AGENTS notes, referenced architecture/test reports, relevant AgentCore domain classes, existing domain/lifecycle tests, and task-01 builders. Ran 3 scout subagents in the task worktree to map domain constructors/behaviors, lifecycle ordering gaps, and test-layout/helper patterns. Implementation should stay test-only and pure PHPUnit: add focused AgentCore domain boundary tests, use task-01 builders where helpful, extend existing AgentMessage/Lifecycle tests, and avoid invented invariants where production constructors intentionally do not validate.
- Preparation/scout phase complete. Key targets identified: RunState/EventFactory/RunEvent, ToolCall/ToolResult/policy enum contracts, ModelInvocation/ProviderRequest DTO contracts, CoreCommandKind/RoutedCommand/PendingCommand, AgentBusMessage accessors, AgentMessage toArray/custom roles, and lifecycle validateOrder negative edge cases.

## Task workflow update - 2026-06-03T01:38:52.003Z
- Recorded fork run: q97fkoum8ive
- Summary: Launched implementation fork q97fkoum8ive in background on worktree `/home/ineersa/projects/agent-core-worktrees/02-refactor-domain-boundary-tests`. Fork instructions are test-only: add focused pure PHPUnit AgentCore domain boundary tests for RunState/RunStatus, RunEvent/EventFactory, ToolCall/ToolResult/policy, model invocation DTOs, command DTOs, bus message contracts, AgentMessage toArray/custom role behavior, and lifecycle ordering edge cases. Fork must use Castor-only validation, run focused tests plus `castor test` and `LLM_MODE=true castor check` (or exact environmental blockers), commit changes, and return a dense handoff.
- Implementation fork q97fkoum8ive launched with exact file targets, boundaries, and validation commands. Parent will verify commit/diff and record validation when fork reports back.
