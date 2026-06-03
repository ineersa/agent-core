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
Status: TODO
Branch:
Worktree:
Fork run:
PR URL:
PR Status:
Started:
Completed:

## Work log
- Created: 2026-06-03T00:31:45.476Z
