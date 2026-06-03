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

## Task workflow update - 2026-06-03T17:15:09.031Z
- Summary: Revised implementation plan agreed with user:

**Two related changes in one task:**
1. Replace 5-subscriber EventDispatcher chain with single `RuntimeEventTranslator` using `RunEventTypeEnum`-keyed dispatch table
2. Replace `CoreLifecycleEventType` string constants with proper `RunEventTypeEnum` backed enum

**New files (3):**
- `src/AgentCore/Domain/Event/RunEventTypeEnum.php` — backed enum, 23 cases, no methods
- `src/AgentCore/Domain/Event/LifecycleOrderValidator.php` — `validateOrder()` moved from CoreLifecycleEventType
- `src/CodingAgent/Runtime/Protocol/RuntimeEventTranslator.php` — dispatch-table translator keyed by RunEventTypeEnum

**Deleted files (7):**
- `src/AgentCore/Domain/Event/CoreLifecycleEventType.php`
- `src/CodingAgent/Runtime/Protocol/RunEventMappingEvent.php`
- `src/CodingAgent/Runtime/Mapping/RunLifecycleMappingSubscriber.php`
- `src/CodingAgent/Runtime/Mapping/AssistantMessageMappingSubscriber.php`
- `src/CodingAgent/Runtime/Mapping/ToolExecutionMappingSubscriber.php`
- `src/CodingAgent/Runtime/Mapping/HitlMappingSubscriber.php`
- `src/CodingAgent/Runtime/Mapping/CancelAndFallbackMappingSubscriber.php`

**Modified files (~18):**
- 10 `Lifecycle/*Event.php` — `CoreLifecycleEventType::X` → `RunEventTypeEnum::X->value`
- 6 pipeline handlers (LlmStepResultHandler, AdvanceRunHandler, ToolCallResultHandler, StartRunHandler, ApplyCommandHandler, CommandMailboxPolicy) — raw strings and `CoreLifecycleEventType::` refs → `RunEventTypeEnum::X->value`
- RuntimeEventMapper — simplified, delegates to translator, drops EventDispatcher
- config/services.yaml — remove `$dispatcher` injection
- config/packages/agent_core.yaml — remove no-op mapper definition
- LifecycleEventContractTest — use LifecycleOrderValidator::validateOrder()
- RuntimeEventMapperTest — simplify setUp (zero subscriber wiring)

**Design decisions:**
- Dispatch table keyed by `RunEventTypeEnum` (not raw strings)
- `validateOrder()` extracted to separate `LifecycleOrderValidator` class (not on the enum)
- `eventClassMap()` deleted (zero callers)
- `isCore()` removed (enum grouping makes the distinction visible)
- No new test files — existing 28 tests preserved with simplified setup
- HITL-vs-cancel priority is explicit `if/else` in `onAgentCommandApplied()`, no Symfony priority magic

**Net impact:** ~+250 lines new, ~-735 lines deleted, net ~-485 lines

**Implementation order:** Create enum → Create validator → Update Lifecycle events → Update pipeline handlers → Update test → Delete CoreLifecycleEventType → Create translator → Rewrite mapper → Delete subscribers + MappingEvent → Update DI config → Validate

**Validation:** castor test --filter=RuntimeEventMapper, castor test --filter=LifecycleEventContract, castor test, castor deptrac, castor phpstan, castor cs-check
