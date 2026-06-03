# 06-refactor-codingagent-runtime-events: collapse runtime event mapper subscribers

## Goal
Plan: .pi/plans/architecture-refactor-plan.md
Reports: .pi/reports/coding-agent-architecture.md, .pi/reports/tests-architecture.md

Replace the internal RuntimeEventMapper subscriber chain with an explicit, testable runtime event translator while preserving the RuntimeEventMapper public API and all runtime event DTOs.

Scope:
- Consolidate AgentCore event type to RuntimeEvent mapping into one deterministic translator/dispatch table.
- Remove mutable handled-flag and priority-order dependency from runtime event mapping.
- Keep TranscriptProjector/projection subscribers unchanged unless needed for adapter compatibility.
- Update RuntimeEventMapper tests to target the new translator directly.

## Acceptance criteria
- Runtime event mapping no longer depends on Symfony EventDispatcher subscriber priority for correctness.
- RuntimeEventMapper::toRuntimeEvent() and JSONL runtime protocol behavior are preserved.
- Tests cover HITL, cancellation/fallback, tool, lifecycle, stream/status, and unknown-event fallback mappings in one clear suite.
- Run and report Castor validation: castor test --filter=RuntimeEventMapper plus castor test:controller/castor check where prerequisites allow, or exact environmental blockers.

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
- Created: 2026-06-03T00:32:13.067Z
