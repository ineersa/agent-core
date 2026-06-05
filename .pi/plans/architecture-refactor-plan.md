# Architecture Refactor Plan

Generated: 2026-06-02

Source reports:

- `.pi/reports/agent-core-architecture.md`
- `.pi/reports/coding-agent-architecture.md`
- `.pi/reports/tui-architecture.md`
- `.pi/reports/tests-architecture.md`

Tracked task sequence:

1. `tasks/TODO/01-refactor-test-foundations.md`
2. `tasks/TODO/02-refactor-domain-boundary-tests.md`
3. `tasks/TODO/03-refactor-agentcore-mailbox-policy.md`
4. `tasks/TODO/04-refactor-agentcore-pipeline-dependencies.md`
5. `tasks/TODO/05-refactor-agentcore-tool-orchestration.md`
6. `tasks/TODO/06-refactor-codingagent-runtime-events.md`
7. `tasks/TODO/07-refactor-codingagent-controller-pollers.md`
8. `tasks/TODO/08-refactor-codingagent-config-selection.md`
9. `tasks/TODO/09-refactor-tui-runtime-state.md`
10. `tasks/TODO/10-refactor-tui-screen-picker.md`

---

## Executive summary

The codebase architecture is fundamentally healthy: the three major namespaces have clear responsibilities, Deptrac boundaries are doing their job, ExtensionApi is intentionally isolated, runtime/TUI communication is already constrained to contracts/protocol DTOs, and E2E harnesses are unusually strong.

The reports identify a different class of problem: **module depth and testability friction**. Several important concepts are spread across shallow facades, mutable bags, event-dispatch chains, or large orchestrator classes. The goal of this plan is not to redraw the monorepo boundaries. The goal is to deepen modules inside the existing boundaries so callers see smaller interfaces and tests can exercise real behavior at stable seams.

Primary improvement themes:

1. **Stabilize test foundations before refactors**
   - Central builders for noisy domain/runtime objects.
   - One filesystem isolation strategy.
   - Direct domain invariant tests.

2. **Reduce shallow facades and duplicated internal logic**
   - Remove `RunMessageStateTools`.
   - Unify `CommandMailboxPolicy` command application.
   - Extract picker overlay lifecycle.

3. **Make runtime translation/polling explicit and testable**
   - Collapse priority-sensitive runtime mapping subscribers into one translator.
   - Extract headless controller pollers.
   - Split TUI runtime poller concerns.

4. **Shrink monoliths only after tests exist**
   - Extract tool orchestration from `LlmStepResultHandler`.
   - Split `ChatScreen` into sections.
   - Decompose `TuiSessionState` into invariant-bearing state objects.

---

## Non-goals

- Do not change the high-level `src/AgentCore`, `src/CodingAgent`, `src/Tui` boundaries.
- Do not introduce HTTP/web framework behavior.
- Do not add backward-compatibility shims unless the surface is truly public, such as `ExtensionApi`.
- Do not add production APIs solely for tests.
- Do not replace strong E2E smoke tests with unit tests; add unit/boundary tests so E2E remains product-level validation rather than the only diagnostic signal.
- Do not use raw `vendor/bin/*` QA commands as primary validation. Use Castor.

---

## Architectural principles for the refactor

### 1. Deep modules over thin routing layers

A deep module should hide real implementation complexity behind a small interface. Shallow patterns to avoid:

- pass-through facades with many unrelated methods;
- `EventDispatcher` chains where there is no runtime extensibility requirement;
- mutable DTOs used as handoff protocols between internal subscribers;
- service classes whose interface is nearly as complex as their implementation.

### 2. Preserve existing contracts unless a task explicitly says otherwise

Most tasks are internal refactors. Public or cross-boundary contracts that should remain stable include:

- AgentCore persisted event payloads and lifecycle event ordering;
- `RuntimeEvent` JSONL protocol DTOs;
- `AgentSessionClient` boundary for TUI/runtime communication;
- `ExtensionApi` namespace and DTO/interface semantics;
- TUI extension slot model through `TuiSlotRegistry` and `TuiExtensionContext`.

### 3. Prefer explicit pure components for state transitions

Where a class currently mixes polling, parsing, mapping, deduplication, state transitions, and projection, extract pure or near-pure components first:

- event type + payload -> runtime event;
- runtime event + current activity -> next activity;
- runtime event + current usage -> next usage projection;
- buffered bytes -> parsed JSONL messages.

### 4. Product-level validation remains mandatory for runtime/TUI changes

For controller/runtime/TUI changes, `castor check` is the expected pre-handoff validation because it includes controller E2E, real LLM smoke, and TUI E2E. If tmux or llama.cpp on port 9052 is unavailable, report the exact blocker and keep the task in progress.

---

## Phase plan

### Phase 0 — Baseline and guardrails

Before claiming task 01, confirm:

- working tree is clean enough for task workflow;
- `.pi/reports/*.md` remain available as evidence;
- existing task queue is understood;
- validation prerequisites are known:
  - `tmux` for TUI E2E;
  - llama.cpp test endpoint on port 9052 for real LLM/controller/TUI smoke.

No tracked task was created for this phase; it is an orchestration checklist.

---

### Phase 1 — Test foundations

#### Task 01 — `01-refactor-test-foundations`

File: `tasks/TODO/01-refactor-test-foundations.md`

Goal: Create shared test infrastructure that reduces friction for every later task.

Work items:

- Add `tests/Support/Builder/` builders for common construction-heavy objects:
  - `RunStateBuilder`;
  - `StartRunMessageBuilder` / `AdvanceRunMessageBuilder` or equivalent;
  - `ToolCallBuilder`;
  - `ToolCallResultBuilder`;
  - optional `LlmStepResultBuilder` if immediately useful.
- Extract a reusable filesystem isolation helper from the three current patterns:
  - kernel tests with `chdir()` isolation;
  - pure filesystem tests using `/tmp`;
  - E2E tests using `var/tmp/<prefix>-<uniqid>`.
- Migrate only representative tests at first to prove the design. Avoid noisy whole-suite churn.

Design notes:

- Builders live in tests only.
- Builders should use production constructors and defaults; no reflection or constructor bypass.
- Isolation helper should support both `try/finally` style and PHPUnit lifecycle style.
- Keep test DB behavior aligned with configured Symfony/DAMA test setup.

Why first:

Later AgentCore and runtime tasks need focused tests. Without builders/isolation, every task pays the cost of long constructors and duplicated temp-dir management.

Validation:

- `castor test`
- `castor check` if prerequisites allow; otherwise exact blocker output.

#### Task 02 — `02-refactor-domain-boundary-tests`

File: `tasks/TODO/02-refactor-domain-boundary-tests.md`

Goal: Add direct tests for AgentCore domain invariants before refactoring handlers and runtime code that depend on them.

Work items:

- Test `RunState` status/version/turn/step invariants directly.
- Test `ToolCall`, `ToolResult`, execution mode/policy constraints.
- Test `AgentMessage` content/payload behavior and model invocation DTO contracts.
- Extend lifecycle ordering coverage where the current contract test has gaps.
- Use builders from task 01 where helpful.

Design notes:

- These should be pure unit tests unless a dependency truly requires kernel boot.
- The tests should assert domain behavior, not handler implementation details.
- Avoid exhaustive type-system tests that only restate constructor signatures.

Why second:

It locks down the semantic foundation before refactoring `RunMessageStateTools`, `LlmStepResultHandler`, and runtime projection/mapping code.

Validation:

- focused `castor test --filter=...` for new tests;
- `castor test`;
- `castor check` if prerequisites allow.

---

### Phase 2 — Low-risk AgentCore deepening

#### Task 03 — `03-refactor-agentcore-mailbox-policy`

File: `tasks/TODO/03-refactor-agentcore-mailbox-policy.md`

Goal: Remove duplicated command-application loops from `CommandMailboxPolicy` without changing behavior.

Current friction:

- `applyPendingTurnStartCommands()` and `applyPendingStopBoundaryCommands()` contain near-duplicate iteration, steer superseding, validation, hydration, event emission, and extension command handling.
- The real difference is boundary semantics: terminal-state handling and `shouldContinue` tracking.

Proposed shape:

- Keep the two public methods for existing callers.
- Add an internal boundary enum or value object, e.g. `CommandApplicationBoundaryEnum::TurnStart` / `StopBoundary`.
- Move shared loop into one internal method returning a structured result.
- Make boundary-specific differences explicit and small.

Risk:

Low. Existing tests already cover many edge cases. The danger is accidental event-order or `shouldContinue` drift.

Validation:

- `castor test --filter=CommandMailboxPolicy`
- `castor test`
- `castor check`

#### Task 04 — `04-refactor-agentcore-pipeline-dependencies`

File: `tasks/TODO/04-refactor-agentcore-pipeline-dependencies.md`

Goal: Remove the shallow `RunMessageStateTools` facade and improve message conversion ownership.

Current friction:

- `RunMessageStateTools` combines event factory access, state versioning, stale result checks, message normalization, and tool-call extraction.
- Every pipeline handler depends on the same facade even though each uses a small subset.
- `AgentMessageNormalizer` lives in Domain while importing Symfony AI types.

Proposed shape:

- Delete or retire `RunMessageStateTools`.
- Inject focused collaborators into each handler:
  - `EventFactory` where event specs are built;
  - `ToolCallExtractor` only where tool calls are extracted;
  - message conversion service only where assistant messages are converted.
- Move `AgentMessageNormalizer` to an application/infrastructure boundary namespace if feasible.
- If deeper Symfony AI coupling remains in domain DTOs, document it as a follow-up rather than hiding it behind a compatibility shim.

Risk:

Low to medium. Constructor churn across handlers/tests, but behavior should be unchanged.

Validation:

- affected pipeline handler tests;
- `castor deptrac`;
- `castor test`;
- `castor check`.

#### Task 05 — `05-refactor-agentcore-tool-orchestration`

File: `tasks/TODO/05-refactor-agentcore-tool-orchestration.md`

Goal: Extract the tool-execution orchestration branch from `LlmStepResultHandler`.

Current friction:

`LlmStepResultHandler` handles stale results, abort/cancel, LLM error, assistant response, stop-boundary commands, tool call extraction, policy/schema resolution, tool batch setup, execution effects, follow-up scheduling, and metrics callbacks.

Proposed shape:

- Introduce a focused service such as `ToolCallOrchestrator`.
- The orchestrator receives the run state, LLM result, extracted tool calls, active tool set context, and needed policy/schema dependencies.
- It returns a small result DTO containing:
  - emitted tool-start/batch events;
  - `ExecuteToolCall` effects;
  - pending tool state updates;
  - any schema/policy metadata needed by event payloads.
- Keep `LlmStepResultHandler` responsible for run-state transitions and deciding which branch to take.

Risk:

Medium. Tool-call event payloads and ordering are user-visible through runtime projection and persisted events. Do not change them casually.

Validation:

- new orchestrator unit tests;
- `castor test --filter=LlmStepResultHandler`;
- `castor test`;
- `castor check`.

---

### Phase 3 — CodingAgent runtime and config seams

#### Task 06 — `06-refactor-codingagent-runtime-events`

File: `tasks/TODO/06-refactor-codingagent-runtime-events.md`

Goal: Replace the internal runtime mapping subscriber chain with one explicit translator.

Current friction:

- `RuntimeEventMapper` dispatches AgentCore event strings through Symfony EventDispatcher.
- Five subscribers co-own a deterministic translation table.
- Correctness depends on a mutable `handled` flag and subscriber priorities, especially HITL vs cancellation/fallback mapping.
- Tests must manually wire every subscriber.

Proposed shape:

- Keep `RuntimeEventMapper::toRuntimeEvent()` as the public entry point.
- Move mapping logic into one `RuntimeEventTranslator` or equivalent.
- Use explicit event-family methods or a dispatch table keyed by AgentCore event type.
- Unknown events keep the current fallback behavior.
- Drop events remain explicit in the translator so the intended behavior is visible.

Risk:

Low to medium. Mapping behavior is already tested; the main risk is missing one event family or changing fallback/debug metadata.

Validation:

- `castor test --filter=RuntimeEventMapper`
- `castor test:controller`
- `castor check` if prerequisites allow.

#### Task 07 — `07-refactor-codingagent-controller-pollers`

File: `tasks/TODO/07-refactor-codingagent-controller-pollers.md`

Goal: Shrink `HeadlessController` by extracting event-drain and stdout-stream pollers.

Current friction:

`HeadlessController` owns stdin, LLM stdout polling, canonical event draining, consumer supervision, signal handling, transcript persistence, orphan cleanup, and shutdown. It is product-critical but mostly covered only by E2E.

Proposed shape:

- `EventDrainPoller`:
  - track per-run event cursors;
  - read canonical events from the in-process session client;
  - map to runtime events;
  - emit JSONL;
  - feed transcript persistence.
- `StdoutStreamPoller` / `JsonlLineBuffer`:
  - read nonblocking stdout;
  - retain partial-line buffer;
  - parse JSONL stream deltas;
  - emit transient events;
  - classify parse/read errors.
- Keep `HeadlessController` responsible for EventLoop registration, lifecycle, signal handling, and high-level wiring.

Risk:

Medium. Must preserve event ordering between transient deltas and canonical events, partial-line behavior, and shutdown semantics.

Validation:

- new poller unit tests;
- `castor test:controller`;
- `castor check` if prerequisites allow.

#### Task 08 — `08-refactor-codingagent-config-selection`

File: `tasks/TODO/08-refactor-codingagent-config-selection.md`

Goal: Split pure model/reasoning resolution from persistence and simplify config path resolution.

Current friction:

- `ModelSelectionService` mixes model resolution, reasoning resolution, settings writes, session metadata writes, favorites cache, and display/catalog concerns.
- `AppConfigLoader::resolveConfigPaths()` contains repeated hardcoded if-blocks for path-bearing settings.

Proposed shape:

- `ModelResolver` owns read-only selection:
  - explicit request;
  - session metadata;
  - configured defaults;
  - first available fallback.
- `ModelSettingsPersister` owns writes to home settings and session metadata.
- `ModelSelectionService` remains as a coordinator/facade for existing callers while internals are split.
- Path resolution becomes declarative, e.g. list of config paths and whether each is scalar/list.

Risk:

Medium for model selection due to TUI/controller usage. Low for path-map refactor.

Validation:

- `castor test --filter=ModelSelectionService`
- `castor test --filter=AppConfigLoader`
- `castor test`
- `castor check`.

---

### Phase 4 — TUI runtime and screen composition

#### Task 09 — `09-refactor-tui-runtime-state`

File: `tasks/TODO/09-refactor-tui-runtime-state.md`

Goal: Decompose `RuntimeEventPoller` and `TuiSessionState` into testable state components.

Current friction:

- `RuntimeEventPoller` handles throttling, event fetching, iterator normalization, deduplication, activity transitions, usage extraction, placeholder removal, projection sync, and fatal/nonfatal error handling.
- `TuiSessionState` has many public mutable fields and implicit invariants.

Proposed shape:

- `ActivityStateMachine` or equivalent pure component:
  - current activity + runtime event -> next activity.
- `UsageProjection` / `TurnMetrics` component:
  - enforces per-turn reset and token/cost accumulation rules.
- Sequencing/dedup object:
  - owns last sequence and duplicate suppression.
- `RuntimeEventPoller` remains the orchestrator that composes these pieces and preserves the existing caller contract.

Risk:

Medium-high. This touches listeners and user-visible working/status/footer behavior.

Validation:

- new TUI runtime unit tests;
- `castor test:tui`;
- `castor check` if prerequisites allow.

#### Task 10 — `10-refactor-tui-screen-picker`

File: `tasks/TODO/10-refactor-tui-screen-picker.md`

Goal: Improve TUI composition testability by splitting `ChatScreen` and extracting shared picker overlay behavior.

Current friction:

- `ChatScreen` is a 440-line object with many `LiveTextWidget` fields and closure producers.
- Production screen rendering and testable `ChatLayout` rendering are separate paths that can diverge.
- `ModelPickerController` and `FavoritePickerController` duplicate overlay lifecycle code.

Proposed shape:

- Extract `PickerOverlay` first:
  - owns mount/unmount/focus/close lifecycle;
  - controllers provide item-building and selection behavior.
- Split `ChatScreen` into per-section objects or a unified screen model:
  - header;
  - transcript/history;
  - pending messages;
  - working/status;
  - extension widgets;
  - editor;
  - footer.
- Preserve:
  - TUI extension slot behavior;
  - listener-facing `ChatScreen` API;
  - mount order;
  - terminal-resize responsiveness;
  - startup snapshot expectations unless intentionally changed.
- Remove empty widget stubs only if they remain unused and are not part of a planned public API.

Risk:

Medium. Visual/layout regressions are possible even if logic tests pass.

Validation:

- focused section/overlay tests;
- `castor test:tui`;
- `castor check` if prerequisites allow;
- for risky visual changes, also run `castor run:agent-test` and capture snapshot/session diagnostics on failure.

---

## Dependency order

Recommended order:

```text
01 -> 02
01 -> 03
01 -> 04 -> 05
01 -> 06 -> 07
01 -> 08
01 -> 09 -> 10
```

More detailed sequencing:

1. Start with task 01.
2. Run task 02 after builders exist.
3. Task 03 can run after 01 and is a low-risk AgentCore warm-up.
4. Task 04 should precede task 05 because removing `RunMessageStateTools` clarifies `LlmStepResultHandler` dependencies.
5. Task 06 should precede task 07 because controller pollers should depend on the final event mapping API.
6. Task 08 can run independently after 01.
7. Task 09 should precede task 10 if section splitting would otherwise preserve mutable `TuiSessionState` coupling.
8. Task 10 should be last among the initial batch because visual/TUI refactors have the broadest product-validation surface.

Parallelism opportunities:

- Task 03 and task 08 can run in parallel after task 01.
- Task 06 and task 04 can run in parallel after task 01/02 because they touch different namespaces.
- Task 09 should not run in parallel with task 10.
- Task 04 and task 05 should not run in parallel.
- Task 06 and task 07 should not run in parallel.

---

## Validation strategy

All QA must use Castor.

Default per-task validation:

1. Focused command for changed area, e.g.:
   - `castor test --filter=CommandMailboxPolicy`
   - `castor test --filter=RuntimeEventMapper`
   - `castor test --filter=ModelSelectionService`
2. Broader `castor test`.
3. `castor deptrac` for architecture-boundary-sensitive changes.
4. `castor check` before handoff.

Runtime/TUI-specific validation:

- CodingAgent controller/runtime changes:
  - `castor test:controller`
  - `castor check`
- Real LLM path changes:
  - `castor test:llm-real`
  - `castor check`
- TUI runtime/screen changes:
  - `castor test:tui`
  - `castor check`
  - optionally `castor run:agent-test` for risky visual/interaction changes.

If tmux or llama.cpp prerequisites are missing, the task should remain in progress and the blocker should be recorded exactly.

---

## Risk register

| Risk | Affected tasks | Mitigation |
|---|---:|---|
| Event ordering drift in AgentCore pipeline | 03, 05 | Preserve existing event payload tests; add focused event-order assertions; run controller/LLM/TUI E2E through `castor check`. |
| Runtime mapping fallback changes | 06 | Golden/table-driven tests for every known event family and unknown fallback. |
| Controller stdout buffering regression | 07 | Unit-test partial-line JSONL parsing and stream error handling; run controller E2E. |
| TUI footer/activity state regression | 09 | Extract pure state machine/usage tests before rewiring listeners. |
| Visual layout snapshot churn | 10 | Preserve mount order; update snapshots only with intentional documented visual change; run TUI E2E. |
| Over-broad test churn from builders | 01 | Migrate representative tests only; avoid suite-wide rewrites in the foundation task. |
| Hidden dependency on current mutable public state | 09, 10 | Introduce methods/sub-objects incrementally; avoid forcing every listener to change at once unless tests are in place. |

---

## Success metrics

The refactor program is successful if:

- New feature/refactor tasks require less inline domain object construction.
- Domain invariants have direct tests instead of only handler-level coverage.
- `CommandMailboxPolicy`, `LlmStepResultHandler`, `HeadlessController`, `RuntimeEventPoller`, and `ChatScreen` each expose smaller, more focused collaborators.
- Runtime event mapping is readable in one place and no longer priority-sensitive.
- TUI activity/footer state transitions are testable without tmux.
- `castor deptrac` remains clean.
- `castor check` passes before each task reaches code review, or environmental blockers are recorded exactly.

---

## Deferred candidates not in this first task batch

The reports also identified useful follow-ups that are intentionally deferred:

- `RunCommit` post-commit pipeline extraction.
- `ToolBatchCollector` durable serialization cleanup.
- `AdvanceRunScheduler` extraction from duplicated callbacks.
- `ExtensionToolHookEventSubscriber` -> plain `ToolExecutionHookDispatcher` bridge.
- Theme token extensibility and `ThemeColorEnum` surface reduction.
- TranscriptProjector test decomposition.
- ExtensionApi exhaustive contract tests, if needed beyond a lightweight boundary suite.

These are lower priority than the ten tracked tasks because they either depend on earlier seams, are less urgent, or have a less direct testability payoff.
