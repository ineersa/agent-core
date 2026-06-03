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
Status: DONE
Branch: task/06-refactor-codingagent-runtime-events
Worktree: /home/ineersa/projects/agent-core-worktrees/06-refactor-codingagent-runtime-events
Fork run:
PR URL: https://github.com/ineersa/agent-core/pull/88
PR Status: merged
Started: 2026-06-03T17:15:31.493Z
Completed: 2026-06-03T18:32:57.604Z

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

## Task workflow update - 2026-06-03T17:15:31.493Z
- Moved TODO → IN-PROGRESS.
- Created branch task/06-refactor-codingagent-runtime-events.
- Created worktree /home/ineersa/projects/agent-core-worktrees/06-refactor-codingagent-runtime-events.
- Copied vendor directory into /home/ineersa/projects/agent-core-worktrees/06-refactor-codingagent-runtime-events.
- Copied .vera index into /home/ineersa/projects/agent-core-worktrees/06-refactor-codingagent-runtime-events.

## Task workflow update - 2026-06-03T17:19:55.797Z
- Task claimed, moved to IN-PROGRESS. Branch task/06-refactor-codingagent-runtime-events, worktree at /home/ineersa/projects/agent-core-worktrees/06-refactor-codingagent-runtime-events
- Fork zlhq2oqqcowr launched for implementation — 12 steps: create RunEventTypeEnum, create LifecycleOrderValidator, update 10 lifecycle events, update 6 pipeline handlers, update LifecycleEventContractTest, delete CoreLifecycleEventType, create RuntimeEventTranslator, rewrite RuntimeEventMapper, delete 5 subscribers + RunEventMappingEvent, update RuntimeEventMapperTest, update DI config, validate

## Task workflow update - 2026-06-03T17:39:32.506Z
- Validation: castor test — 1587 tests, 4734 assertions, 0 failures; castor deptrac — 0 violations; castor phpstan — 0 errors; castor cs-check — clean
- Summary: Fork zlhq2oqqcowr completed — commit 743e52b2 on branch task/06-refactor-codingagent-runtime-events, 31 files changed (+534/-741 lines). Created RunEventTypeEnum (23-case backed enum), LifecycleOrderValidator, RuntimeEventTranslator (dispatch table); deleted CoreLifecycleEventType, RunEventMappingEvent, 5 mapping subscribers + Mapping/ dir; updated 10 lifecycle events, 6 pipeline handlers, 2 test files, 2 config files. Validation: castor test 1587/4734 PASS, deptrac 0 violations, phpstan 0 errors, cs-check clean. Key discovery: ExtensionApprovalAnswerSubscriber was a hidden dependency on RunEventMappingEvent — updated to accept RunEvent directly; EventDispatcher kept in translator for extension subscriber compatibility.
Castor Check Status: passed
Castor Check Commit: 98fc6a7abcd6743186e6f009364073f754267022
Castor Check Command: LLM_MODE=true castor check
Castor Check Timeout: 240s
Castor Check Completed: 2026-06-03T18:32:15.102Z
Castor Check Output SHA256: 9b242a301ed51e83653ff77081ab1bfe0b9e4e83d8da40e01129f074a84f4c36

## Task workflow update - 2026-06-03T17:49:48.596Z
- Moved IN-PROGRESS → CODE-REVIEW.
- Castor quality gate passed (240s timeout). Commit: 72180502ec5b.
- Pushed task/06-refactor-codingagent-runtime-events to origin.
- branch 'task/06-refactor-codingagent-runtime-events' set up to track 'origin/task/06-refactor-codingagent-runtime-events'.
- Created PR: https://github.com/ineersa/agent-core/pull/88
- Summary: Commit 72180502 on branch task/06-refactor-codingagent-runtime-events, 33 files changed (+574/-761 lines). Review fixes applied: 5 missed raw string literals → RunEventTypeEnum values, stale depfile.yaml regex cleaned, AGENTS.md docs updated, HITL human_response test added. Validation: castor test 1588/4738 PASS, deptrac 0 violations, phpstan 0 errors, cs-check clean. Review verdict: fixes address all critical issues (raw strings) + stale config/docs + test gap.

## Task workflow update - 2026-06-03T18:23:53.061Z
- Moved CODE-REVIEW → IN-PROGRESS.

## Task workflow update - 2026-06-03T18:26:22.785Z
- Validation: castor test — 1588 tests, 4738 assertions, 0 failures; castor deptrac — 0 violations; castor phpstan — 0 errors; castor cs-check — clean
- Summary: PR #88 review feedback addressed in commit 98fc6a7a (amended). 4 comments resolved: (1) added RunEventTypeEnum::isLifecycleType() static method replacing raw-string LIFECYCLE_TYPES constant in LifecycleOrderValidator, (2) made EventDispatcherInterface non-nullable in RuntimeEventTranslator + RuntimeEventMapper constructors since it's always autowired, (3) updated RuntimeEventMapperTest setUp to provide explicit EventDispatcher. 33 files changed (+582/-762). Validation: castor test 1588/4738 PASS, deptrac 0 violations, phpstan 0 errors, cs-check clean.

## Task workflow update - 2026-06-03T18:32:16.522Z
- Moved IN-PROGRESS → CODE-REVIEW.
- Castor quality gate passed (240s timeout). Commit: 98fc6a7abcd6.
- Pushed task/06-refactor-codingagent-runtime-events to origin.
- branch 'task/06-refactor-codingagent-runtime-events' set up to track 'origin/task/06-refactor-codingagent-runtime-events'.
- PR already exists: https://github.com/ineersa/agent-core/pull/88

## Task workflow update - 2026-06-03T18:32:57.604Z
- Moved CODE-REVIEW → DONE.
- Merged task/06-refactor-codingagent-runtime-events into integration checkout.
- Merge made by the 'ort' strategy.
 config/packages/agent_core.yaml                    |   2 -
 config/services.yaml                               |  11 +-
 depfile.yaml                                       |   2 +-
 .../Application/Pipeline/AdvanceRunHandler.php     |   6 +-
 .../Application/Pipeline/ApplyCommandHandler.php   |  13 +-
 .../Application/Pipeline/CommandMailboxPolicy.php  |  15 +-
 .../Application/Pipeline/LlmStepResultHandler.php  |  16 +-
 .../Application/Pipeline/StartRunHandler.php       |   3 +-
 .../Application/Pipeline/ToolCallResultHandler.php |  22 +-
 src/AgentCore/Domain/Event/AGENTS.md               |  30 +-
 .../Domain/Event/Lifecycle/AgentEndEvent.php       |   4 +-
 .../Domain/Event/Lifecycle/AgentStartEvent.php     |   4 +-
 .../Domain/Event/Lifecycle/MessageEndEvent.php     |   4 +-
 .../Domain/Event/Lifecycle/MessageStartEvent.php   |   4 +-
 .../Domain/Event/Lifecycle/MessageUpdateEvent.php  |   4 +-
 .../Event/Lifecycle/ToolExecutionEndEvent.php      |   4 +-
 .../Event/Lifecycle/ToolExecutionStartEvent.php    |   4 +-
 .../Event/Lifecycle/ToolExecutionUpdateEvent.php   |   4 +-
 .../Domain/Event/Lifecycle/TurnEndEvent.php        |   4 +-
 .../Domain/Event/Lifecycle/TurnStartEvent.php      |   4 +-
 ...leEventType.php => LifecycleOrderValidator.php} | 105 ++----
 src/AgentCore/Domain/Event/RunEventTypeEnum.php    |  62 ++++
 .../ExtensionApprovalAnswerSubscriber.php          |  30 +-
 .../Mapping/AssistantMessageMappingSubscriber.php  | 141 --------
 .../Mapping/CancelAndFallbackMappingSubscriber.php | 100 ------
 .../Runtime/Mapping/HitlMappingSubscriber.php      |  93 ------
 .../Mapping/RunLifecycleMappingSubscriber.php      |  93 ------
 .../Mapping/ToolExecutionMappingSubscriber.php     |  75 -----
 .../Runtime/Protocol/RunEventMappingEvent.php      |  36 --
 .../Runtime/Protocol/RuntimeEventMapper.php        |  33 +-
 .../Runtime/Protocol/RuntimeEventTranslator.php    | 370 +++++++++++++++++++++
 .../Contract/LifecycleEventContractTest.php        |  10 +-
 .../CodingAgent/Runtime/RuntimeEventMapperTest.php |  36 +-
 33 files changed, 582 insertions(+), 762 deletions(-)
 rename src/AgentCore/Domain/Event/{CoreLifecycleEventType.php => LifecycleOrderValidator.php} (55%)
 create mode 100644 src/AgentCore/Domain/Event/RunEventTypeEnum.php
 delete mode 100644 src/CodingAgent/Runtime/Mapping/AssistantMessageMappingSubscriber.php
 delete mode 100644 src/CodingAgent/Runtime/Mapping/CancelAndFallbackMappingSubscriber.php
 delete mode 100644 src/CodingAgent/Runtime/Mapping/HitlMappingSubscriber.php
 delete mode 100644 src/CodingAgent/Runtime/Mapping/RunLifecycleMappingSubscriber.php
 delete mode 100644 src/CodingAgent/Runtime/Mapping/ToolExecutionMappingSubscriber.php
 delete mode 100644 src/CodingAgent/Runtime/Protocol/RunEventMappingEvent.php
 create mode 100644 src/CodingAgent/Runtime/Protocol/RuntimeEventTranslator.php
- Removed worktree /home/ineersa/projects/agent-core-worktrees/06-refactor-codingagent-runtime-events.
- Pulled integration checkout: Merge made by the 'ort' strategy..
