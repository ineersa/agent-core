# RTVS-11 Runtime follow-ups and RTVS replan

## Goal
Context after RTVS-07 merge:

RTVS-07 is merged and real `castor test:llm-real` passed for two-turn TUI/LLM flow. However several architectural/runtime follow-ups were discovered and should be handled before RTVS-08 replay work.

## Implementation — completed in fork run

### AC1: Authoritative TUI run activity state (replaces getWorkingMessage heuristic)

- Created `src/Tui/Runtime/RunActivityStateEnum` with states:
  `Idle`, `Starting`, `Running`, `WaitingHuman`, `Cancelling`, `Completed`, `Failed`, `Cancelled`
- Added `RunActivityStateEnum::$activity` field to `TuiSessionState` (default: `Idle`)
- Updated `SubmitListener` to use `$state->activity->isActive()` instead of
  `$screen->registry()->getWorkingMessage() !== ''`
  - Idle → `follow_up` (transitioned to `Starting` optimistically)
  - Active → `steer`
- Updated `RuntimeEventPoller` with `updateActivity()` method that transitions
  `$state->activity` based on each observed `RuntimeEventTypeEnum` value:
  - Start/turn/assistant/tool/user events → `Running`
  - HITL request events → `WaitingHuman`
  - Cancel request → `Cancelling`
  - Run completion/failure/cancellation → terminal states
  - Terminal states are never overwritten

### AC2: Fix after_turn_commit_hook_failed warning

Root cause: Production serializer lacked `ArrayDenormalizer` and
`PhpDocExtractor`, so `HookDispatcher::dispatchAfterTurnCommit()` could not
denormalize `AfterTurnCommitHookContext::events` to
`list<AfterTurnCommitEventSummary>`, causing the constructor type check to fail.

Fix: Updated `config/packages/serializer.yaml` with full serializer metadata:
- Added `ArrayDenormalizer` to normalizer stack
- Configured `ObjectNormalizer` with `ClassMetadataFactory` (AttributeLoader),
  `MetadataAwareNameConverter`, and `PropertyInfoExtractor` (PhpDocExtractor +
  ReflectionExtractor)
- All supporting services wired as named DI services

Previously the warning fired on **every** commit. With the fix, the denormalize
round-trip in HookDispatcher produces correctly typed objects.

### AC3: RTVS-08/09/10 task scope updates per user decision

- RTVS-09: **Removed** (`rm tasks/TODO/rtvs-09-*.md`)
- RTVS-10: **Removed** (`rm tasks/TODO/rtvs-10-*.md`)
- RTVS-08: Updated with explicit dependency on RTVS-11 AC1 + AC2

### AC4: Async/process runtime plan

Separate plan document created at `docs/async-process-runtime-plan.md` covering:
- Architecture: TUI process ↔ headless control process ↔ worker runtime
- Command ack latency targets
- Graceful vs hard cancel ladder
- Steer queue and application at safe boundaries
- Non-blocking LLM/polling constraints
- Implementation phases

## Acceptance criteria
- [x] Replace `getWorkingMessage()`-based follow_up/steer decision with an authoritative run activity signal.
- [ ] Validate follow_up vs steer semantics with a real product-level flow (`castor test:llm-real`).
- [x] Fix universal `after_turn_commit_hook_failed` warning — logs should not emit this warning on every normal commit.
- [x] Reassess RTVS-08/09/10 task scopes and update task files: 09/10 removed; 08 updated with dependency notes.
- [ ] Run and report product-level validation.

## Workflow metadata
Status: IN-PROGRESS
Branch: task/rtvs-11-runtime-followups-and-rtvs-replan
Worktree: /home/ineersa/projects/agent-core-worktrees/rtvs-11-runtime-followups-and-rtvs-replan
Fork run:
PR URL:
PR Status:
Started:
Completed:

## Work log
- Created: 2026-05-21T22:28:39.502Z
- Fork implementation completed: RunActivityStateEnum, TuiSessionState activity field, SubmitListener heuristic removal, RuntimeEventPoller activity transitions, serializer fix, task file cleanup, async process plan.
