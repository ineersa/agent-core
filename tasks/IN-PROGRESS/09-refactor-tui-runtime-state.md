# 09-refactor-tui-runtime-state: decompose runtime poller and session state

## Goal
Plan: .pi/plans/architecture-refactor-plan.md
Reports: .pi/reports/tui-architecture.md, .pi/reports/tests-architecture.md

Turn RuntimeEventPoller and TuiSessionState into deeper, testable runtime components with explicit invariants for activity state, sequencing, and footer usage.

Scope:
- Extract activity state transition logic into a pure state machine/service.
- Extract footer usage/turn metrics accumulation into an invariant-bearing object or service.
- Split TuiSessionState into structured sub-objects for run handle, sequencing, footer projection, and turn metrics where practical.
- Preserve RuntimeEventPoller::poll() caller contract for listeners.

## Acceptance criteria
- Activity transitions and usage extraction have focused unit tests independent of full TUI/tmux E2E.
- Per-turn metric reset invariants are enforced by methods/objects rather than comment-only conventions.
- Existing listener behavior and RuntimeEventPoller public caller contract are preserved.
- Run and report Castor validation: new TUI runtime tests, castor test:tui and castor check where prerequisites allow, or exact environmental blockers.

## Workflow metadata
Status: IN-PROGRESS
Branch: task/09-refactor-tui-runtime-state
Worktree: /home/ineersa/projects/agent-core-worktrees/09-refactor-tui-runtime-state
Fork run: nh1ndqls53r7
PR URL:
PR Status:
Started: 2026-06-03T23:47:17.633Z
Completed:

## Work log
- Created: 2026-06-03T00:32:15.420Z
- Plan finalized: 2026-06-03 (user decisions: mutable UsageProjection, single usage object, throttle/dedup inline in RuntimeEventPoller)

## Implementation Plan

### Extractions (3)

**1. ActivityStateMachine** (~80 lines)
- Pure state transition function: `transition(RunActivityStateEnum, RuntimeEvent): RunActivityStateEnum`
- Terminal state guard built-in (`Completed`, `Failed`, `Cancelled` are sticky)
- ~24 event type transitions (RunStarted, TurnStarted/Completed, AssistantMessageStarted/Completed, etc.)
- No side effects — pure determinism from (state, event) → next state

**2. UsageProjection** (mutable, ~60 lines)
- Single object holds BOTH session-level and per-turn metrics
- Session: `inputTokens`, `outputTokens`, `totalCost`
- Per-turn: `turnOutputTokens`, `turnStartTime`, `llmEndTime`, `latestInputTokens`
- Methods:
  - `resetTurn()` — called on TurnStarted; resets turnOutputTokens=0, turnStartTime=microtime(), llmEndTime=0.0, latestInputTokens=0
  - `accumulate(RuntimeEvent)` — called on AssistantMessageCompleted; reads usage payload with fallback keys, accumulates tokens/cost
- Invariant: `resetTurn()` must be called before `accumulate()` on a new turn (enforced by the single call site in poller)

**3. RuntimeEventPoller rewrite** (~200 lines, from 344)
- Thin poll loop in `poll()`:
  - Throttling stays inline (50ms `lastPoll` check)
  - Dedup stays inline (seq > 0 && seq <= lastSeq → skip)
  - Delegates activity transitions to ActivityStateMachine.transition()
  - Delegates usage extraction to UsageProjection.resetTurn()/accumulate()
  - Projector ingestion stays in poller
  - Placeholder removal stays in poller (`removeProcessingPlaceholder()`)
  - Projection sync stays in poller (`synchronizeProjectedBlocks()`)
  - Error handling stays in poller (fatal/non-fatal classification, boundary delegation)

**NOT extracted**: SequenceTracker (dedup is trivial 3-line inline check). Throttling stays inline. TurnMetrics merged into UsageProjection.

### TuiSessionState restructure

Replace 8 flat properties with a single `UsageProjection $usage` sub-object:

| Removed properties | Replacement |
|---|---|
| `$inputTokens` | `$usage->inputTokens` |
| `$outputTokens` | `$usage->outputTokens` |
| `$totalCost` | `$usage->totalCost` |
| `$turnOutputTokens` | `$usage->turnOutputTokens` |
| `$turnStartTime` | `$usage->turnStartTime` |
| `$llmEndTime` | `$usage->llmEndTime` |
| `$latestInputTokens` | `$usage->latestInputTokens` |

Other properties remain flat on TuiSessionState:
- `$sessionId`, `$resuming` (identity, constructor-only)
- `$handle`, `$request` (run handle)
- `$activity` (RunActivityStateEnum)
- `$transcript` (TranscriptBlock[])
- `$lastSeq`, `$lastPoll` (poller-owned inline)
- `$runtimePollErrorCount`, `$lastRuntimePollError` (poller-owned)
- `$footerModel`, `$footerReasoning`, `$contextWindow` (set-once by FooterStateInitializer)
- `$cwd`, `$branch`, `$sessionStartTime` (environment, set-once)

### Caller updates (mechanical)

6 files change `$state-><flatProp>` → `$state->usage-><prop>`:
- `src/Tui/Listener/TickPollListener.php`
- `src/Tui/Listener/SubmitListener.php`
- `src/Tui/Listener/FooterStateSegmentProvider.php`
- `src/Tui/Listener/FooterStateInitializer.php`
- `src/Tui/Picker/ModelPickerController.php`
- `src/Tui/Picker/FavoritePickerController.php`

### New test files

| Test file | Tests | Coverage |
|---|---|---|
| `tests/Tui/Runtime/ActivityStateMachineTest.php` | ~24 | All event-type transitions, terminal guard, default fallback |
| `tests/Tui/Runtime/UsageProjectionTest.php` | ~10 | Accumulation, per-turn reset, cost calculation, edge cases (missing payload keys, zero values) |
| `tests/Tui/Runtime/RuntimeEventPollerTest.php` | ~5 | Integration with mocked ActivityStateMachine/UsageProjection, error handling, empty poll, dedup |

### Design decisions

- **UsageProjection is mutable** (hot path — accumulate/reset methods are simpler and more natural than immutable with() copies)
- **Single UsageProjection** holds both session and per-turn metrics (not separate TurnMetrics + SessionMetrics DTOs)
- **Throttling stays inline** in RuntimeEventPoller (not extracted to a separate class)
- **Dedup stays inline** in RuntimeEventPoller (trivial 3-line check, not extracted)

### Implementation order

1. Create `ActivityStateMachine` — pure transition function, no deps
2. Create `UsageProjection` — mutable accumulator, no deps
3. Add `ActivityStateMachineTest` + `UsageProjectionTest` — validate in isolation
4. Restructure `TuiSessionState` — replace 8 properties with `UsageProjection` sub-object; constructor + public read accessors
5. Update all callers — 6 files, mechanical property path changes
6. Rewrite `RuntimeEventPoller::poll()` — thin loop delegating to new components; throttling/dedup/error handling stay
7. Add `RuntimeEventPollerTest` — integration with mocks
8. Validate — run full QA suite

### Validation commands

```
castor test --filter=ActivityStateMachine
castor test --filter=UsageProjection
castor test --filter=RuntimeEventPoller
castor test
castor deptrac
castor phpstan
castor cs-check
castor test:tui      # if tmux prerequisites available
```

## Task workflow update - 2026-06-03T23:47:17.633Z
- Moved TODO → IN-PROGRESS.
- Created branch task/09-refactor-tui-runtime-state.
- Created worktree /home/ineersa/projects/agent-core-worktrees/09-refactor-tui-runtime-state.
- Copied vendor directory into /home/ineersa/projects/agent-core-worktrees/09-refactor-tui-runtime-state.
- Copied .vera index into /home/ineersa/projects/agent-core-worktrees/09-refactor-tui-runtime-state.

## Task workflow update - 2026-06-03T23:55:07.591Z
- Recorded fork run: nh1ndqls53r7
- Validation: castor test — 1649/4876 pass (+61 new); castor deptrac — 0 violations; castor phpstan — 0 errors; castor cs-check — clean
- Summary: Fork nh1ndqls53r7 completed task 09 — commit a6dddbe3 on branch task/09-refactor-tui-runtime-state, 8 files changed (+967/-137 lines). Created ActivityStateMachine (80 lines, pure transition function with 22 event→Running, 2→WaitingHuman, 3→Cancelling, 1→Completed, 3→Failed, 2→Cancelled + terminal guard), UsageProjection (94 lines, mutable accumulator with resetTurn/accumulate methods), restructured TuiSessionState (8 flat properties → single UsageProjection sub-object), rewrote RuntimeEventPoller (344→~260 lines, delegates to extracted components), updated FooterStateSegmentProvider (7 property redirects). Added 3 test files: ActivityStateMachineTest (41 tests), UsageProjectionTest (9 tests), RuntimeEventPollerTest (11 tests). 1649 tests pass (+61 new), 0 deptrac violations, 0 phpstan errors, cs-check clean. Note: only FooterStateSegmentProvider needed caller updates — other 5 callers from plan don't touch usage properties. TUI E2E not validated (no tmux/llama.cpp in worktree).
