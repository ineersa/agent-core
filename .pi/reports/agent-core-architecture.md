# AgentCore Architecture Deepening Report

**Generated:** 2026-06-02  
**Scope:** `src/AgentCore/` namespace + mirrored tests  
**Method:** Read-only exploration, semantic navigation, call-hierarchy tracing, deptrac analysis

---

## 1. Architecture Overview

AgentCore follows a clean layered architecture within a modular monolith:

```
Contract/        — Pure interfaces (RunStoreInterface, EventStoreInterface, CommandStoreInterface, etc.)
Domain/          — Value objects, enums, events, messages, run state, DTOs (no business logic)
Application/     — Pipeline handlers, orchestrators, services, batch coordinators
Infrastructure/  — Symfony AI adapter, Messenger wiring, in-memory/Doctrine storage
Schema/          — Event serialization (EventPayloadNormalizer)
```

The pipeline operates on two Symfony Messenger buses:
- **`agent.command.bus`** — RunOrchestrator receives StartRun, ApplyCommand, AdvanceRun, LlmStepResult, ToolCallResult
- **`agent.execution.bus`** — ExecuteLlmStepWorker and ExecuteToolCallWorker execute side-effect work

Handlers return `HandlerResult` (nextState + events + effects + postCommit callbacks), which `RunMessageProcessor` commits through `RunCommit` with CAS retry and exponential backoff.

Deptrac validates: **0 violations, 591 uncovered (expected internal calls), 856 allowed**.

---

## 2. Exploration Findings — Friction Points

### 2.1 RunMessageStateTools: God-class Facade (Shallow Module)

**Files:** `src/AgentCore/Application/Pipeline/RunMessageStateTools.php:1-97`

`RunMessageStateTools` is a 97-line pass-through that combines three unrelated concerns into a single service:

| Method | Delegates to | Concern |
|---|---|---|
| `event()` | `EventFactory` | Event construction |
| `eventsFromSpecs()` | `EventFactory` | Batch event construction |
| `isStaleResult()` | (own logic) | State validation |
| `incrementStateVersion()` | `EventFactory` | State versioning |
| `assistantMessage()` | `AgentMessageNormalizer` | Message conversion |
| `assistantMessagePayload()` | `AgentMessageNormalizer` | Payload extraction |
| `extractToolCalls()` | `ToolCallExtractor` | Tool call extraction |

**Problem:** Every pipeline handler (`StartRunHandler`, `AdvanceRunHandler`, `LlmStepResultHandler`, `ToolCallResultHandler`, `ApplyCommandHandler`) depends on `RunMessageStateTools`, but each handler only uses 2-4 of its 7 methods. The interface is nearly as large as the three underlying services combined — a textbook shallow module. Testing a handler requires mocking all three underlying concerns even when the test only exercises one.

**Evidence:** `StartRunHandler` uses only `event()` and `normalizePayload()` (which bypasses stateTools entirely). `LlmStepResultHandler` at `LlmStepResultHandler.php:98` uses `stateTools->extractToolCalls()`, `stateTools->assistantMessage()`, `stateTools->assistantMessagePayload()` — only 3 of 7 methods.

### 2.2 LlmStepResultHandler: God Handler (Overloaded Decision Tree)

**File:** `src/AgentCore/Application/Pipeline/LlmStepResultHandler.php` — **430 lines, 7 constructor dependencies**

This handler has 7 major code paths:
1. **Stale result** (line ~83) — increment version, emit `stale_result_ignored`
2. **Aborted/cancelling** (line ~95) — emit `llm_step_aborted` + `agent_end`, turn-completed callbacks
3. **LLM error** (line ~131) — transition to Failed, emit `llm_step_failed`, turn-completed
4. **No tool calls → stop boundary** (line ~182) — apply stop-boundary commands, decide continue vs complete
5. **Tool calls with execution** (line ~218) — resolve policies, resolve schemas, build ExecuteToolCall effects, emit tool_execution_start events
6. **Follow-up scheduling** (line ~288) — post-commit AdvanceRun callbacks
7. **Turn-completed metrics** (line ~389) — post-commit metrics callbacks

Testing impact: To test the "LLM returns tool calls" path, you must satisfy preconditions for stale-check (turn/step matching), abort-check (stop_reason != 'aborted'), error-check (error == null), tool-call extraction, policy resolution, and schema resolution — even if the test only cares about one branch.

The `resolveToolPolicy()`, `resolveActiveSet()`, `resolveToolSchemas()` private methods at lines 312-380 implement tool-execution concern that could be a separate service. The `followUpAdvanceCallback()` at line 396 is duplicated across 3 other handlers.

### 2.3 CommandMailboxPolicy: Near-Duplicate Boundary Methods

**File:** `src/AgentCore/Application/Pipeline/CommandMailboxPolicy.php` — **425 lines**

`applyPendingTurnStartCommands()` (line 46) and `applyPendingStopBoundaryCommands()` (line 174) share ~80% identical logic:
- Both iterate pending commands from `commandStore->pending()`
- Both handle `supersededSteerKeys()` for steer deduplication
- Both validate/reject/hydrate steer and follow-up commands with the same `AgentMessage::fromPayload()` logic
- Both produce the same event spectrums (`agent_command_superseded`, `agent_command_rejected`, `agent_command_applied`)
- Both delegate extension commands through `applyExtensionCommand()`
- Both call `copyState()` with message overrides

The only difference: `applyPendingStopBoundaryCommands()` tracks `$shouldContinue` and doesn't transition non-terminal states. These two methods are 150+ lines each with identical inner loops.

**Evidence:** Compare lines 78-139 in `applyPendingTurnStartCommands()` to lines 198-256 in `applyPendingStopBoundaryCommands()` — nearly line-for-line.

### 2.4 RunCommit: Overloaded with Cross-Cutting Concerns

**File:** `src/AgentCore/Application/Pipeline/RunCommit.php` — **291 lines, 9 constructor dependencies**

`commit()` handles:
1. CAS state persistence
2. Event persistence (single + batch)
3. Event persistence failure rollback (restore old state, mark run failed)
4. Hot prompt state rebuild (best-effort)
5. Effect dispatch (best-effort)
6. After-turn commit hooks (best-effort)
7. Tracer span wrapping
8. Metrics tracking (status transitions, queue lag, stale results)
9. Structured logging of committed events

Several of these are clearly separable concerns: #4 (replay/rebuild), #6 (hook dispatch), #8 (metrics) are orthogonal to the atomic commit guarantee. The current design forces every test of `RunCommit` to provide all 9 dependencies even when testing only CAS semantics.

### 2.5 ToolBatchCollector: Manual Serialization Leaking Abstraction

**File:** `src/AgentCore/Application/Handler/ToolBatchCollector.php` — **398 lines**

The collector manages both in-memory batch state (`$batches` array) and durable store persistence. The `serializeBatch()` (line ~249-285) and `reconstructBatch()` (line ~292-345) methods manually convert `ExecuteToolCall` and `ToolCallResult` domain objects to/from flat arrays field-by-field. This is 100+ lines of hand-written serialization code that duplicates the object shapes and must be kept in sync whenever `ExecuteToolCall` or `ToolCallResult` gain new fields.

The `reconstructCall()` method at line 347 constructs `ExecuteToolCall` with 14 named parameters — every new field requires updates in 3 places (the class, serialize, and reconstruct).

### 2.6 Domain-Layer Symfony AI Coupling

**Files with infrastructure imports in Domain:**

| File | Symfony AI import | Problem |
|---|---|---|
| `Domain/Message/AgentMessageNormalizer.php:7-9` | `AssistantMessage`, `Thinking`, `ToolCall` | Converter lives in Domain but uses infrastructure types |
| `Domain/Model/PlatformInvocationResult.php:7-8` | `AssistantMessage`, `DeltaInterface` | Domain model carries infrastructure types |
| `Domain/Message/LlmStepResult.php:7` | `AssistantMessage` | Bus message carries infrastructure type |

**Evidence:** `AgentMessageNormalizer.php:15` accepts `AssistantMessage $assistantMessage` — a Symfony AI type. This means Domain cannot be compiled or type-checked without Symfony AI. The normalizer is a *converter* (infrastructure concern), not a domain concept.

### 2.7 followUpAdvanceCallback Duplication Across Handlers

Four handlers contain nearly identical `followUpAdvanceCallback()` methods:
- `StartRunHandler.php:85-106`
- `ApplyCommandHandler.php:352-373`
- `LlmStepResultHandler.php:396-418`
- `ToolCallResultHandler.php:250-271`

Each is a 20-line closure that creates an `AdvanceRun` message and dispatches it. The only variation is the `$prefix` string used in `stepId`. This pattern should be extracted to a shared service.

### 2.8 EventFactory Knows Full RunState Shape

**File:** `src/AgentCore/Domain/Event/EventFactory.php:64-80`

`EventFactory::incrementStateVersion()` constructs a new `RunState` with all 12 named parameters. The EventFactory — a domain factory for events — must know the full RunState constructor signature. When RunState gains a field, EventFactory must be updated. This couples event construction to state management. The method is only called from `RunMessageStateTools::incrementStateVersion()`, which suggests the state versioning concern is misplaced.

### 2.9 Thin Contract Files (Many Single-Method Interfaces)

Several contract files are under 15 lines:
- `Contract/IdempotencyStoreInterface.php` — 2 methods
- `Contract/SpanProviderInterface.php` — likely small
- `Contract/Hook/ConvertToLlmHookInterface.php` — single method
- `Contract/Hook/LlmStreamObserverInterface.php` — 4 methods
- `Contract/Hook/TransformContextHookInterface.php` — single method

These are appropriate for the ports & adapters pattern. However, `Hook/` contains 5 interfaces in 5 files — grouping related hook contracts into a single file or using a trait-based contract would reduce file scatter without changing semantics.

---

## 3. Candidate List — Deepening Opportunities

### Candidate 1: Split `RunMessageStateTools` into Role-Specific Services

**Cluster:** `Application/Pipeline/RunMessageStateTools`, `Domain/Event/EventFactory`, `Application/Pipeline/ToolCallExtractor`, `Domain/Message/AgentMessageNormalizer`

**Why coupled:** All pipeline handlers share a single `RunMessageStateTools` dependency even though each handler uses a different subset of its 7 public methods.

**Dependency category:** Category 2 — Internal coupling (same layer, same namespace). The three underlying services (`EventFactory`, `ToolCallExtractor`, `AgentMessageNormalizer`) are already separate; the facade adds no abstraction value.

**Test impact:** Each handler test currently passes `RunMessageStateTools` with real instances of all three underlying services. After split, handlers would depend only on the 1-2 services they actually use. `StartRunHandlerTest` (tests/AgentCore/Application/Pipeline/StartRunHandlerTest.php) currently constructs `new RunMessageStateTools(new EventFactory(), new ToolCallExtractor())` — only needs `EventFactory`.

**Deepening approach:** Remove `RunMessageStateTools` and inject `EventFactory`, `ToolCallExtractor`, `AgentMessageNormalizer` directly where needed. Each handler would have 1-2 fewer indirections and tests would be simpler.

---

### Candidate 2: Extract Tool Execution Orchestration from `LlmStepResultHandler`

**Cluster:** `Application/Pipeline/LlmStepResultHandler`, `Application/Handler/ToolBatchCollector`, `Application/Pipeline/ToolCallExtractor`, `Contract/Tool/ToolSetResolverInterface`

**Why coupled:** Tool call extraction, policy resolution, schema resolution, and ExecuteToolCall effect construction are a cohesive sub-concern embedded in a 430-line handler.

**Dependency category:** Category 2 — Internal coupling. The tool-execution orchestration logic (lines 155-220 of `LlmStepResultHandler`) is a natural module boundary.

**Test impact:** Currently tests like `tests/AgentCore/Application/Pipeline/LlmStepResultHandlerTest.php` must set up the full handler with all 7 dependencies even to test "LLM returns 2 tool calls, both dispatched." An extracted `ToolCallOrchestrator` could be tested in isolation with only `ToolBatchCollector`, `ToolSetResolverInterface`, `ToolboxInterface`.

**Deepening approach:** Create `Application/Tool/ToolCallOrchestrator` with a single method `orchestrateToolCalls(LlmStepResult, RunState, ActiveToolSet): ToolCallOrchestrationResult` that returns the list of `ExecuteToolCall` effects, tool schemas, and pending state. The `LlmStepResultHandler` would shrink by ~60 lines.

---

### Candidate 3: Unify `CommandMailboxPolicy` Boundary Methods

**Cluster:** `Application/Pipeline/CommandMailboxPolicy`, `Domain/Command/PendingCommand`, `Contract/CommandStoreInterface`

**Why coupled:** `applyPendingTurnStartCommands()` and `applyPendingStopBoundaryCommands()` have ~80% duplicate command-iteration logic.

**Dependency category:** Category 1 — Pure internal deduplication. No external contracts change.

**Test impact:** `tests/AgentCore/Application/Pipeline/CommandMailboxPolicyTest.php` must test both methods separately. A unified core method tested once reduces test surface by ~40%.

**Deepening approach:** Extract a private `applyPendingCommands(RunState, BoundaryType): CommandApplicationResult` that both public methods delegate to. The `BoundaryType` (Start | Stop) controls the `shouldContinue` tracking and terminal-state behavior.

---

### Candidate 4: Move `AgentMessageNormalizer` from Domain to Infrastructure

**Cluster:** `Domain/Message/AgentMessageNormalizer`, `Infrastructure/SymfonyAi/AgentMessageConverter`, `Domain/Model/PlatformInvocationResult`

**Why coupled:** `AgentMessageNormalizer` imports `Symfony\AI\Platform\Message\AssistantMessage` — an infrastructure type — but lives in the Domain layer. This violates the dependency rule (Domain should not depend on Infrastructure).

**Dependency category:** Category 3 — Layer violation. Domain depends on a vendor infrastructure package.

**Test impact:** No current test failures. The risk is silent — future Symfony AI major version changes would force Domain recompilation. Moving the normalizer would require updating imports in `RunMessageStateTools` and all handlers, but they already depend on Application-layer services.

**Deepening approach:** Move `AgentMessageNormalizer` to `Infrastructure/SymfonyAi/` or `Application/Conversion/`. Keep `AgentMessage` (the domain value object) in Domain. The normalizer converts between domain and infrastructure types — it belongs at the boundary.

---

### Candidate 5: Extract `RunCommit` Side-Effects into Post-Commit Pipeline

**Cluster:** `Application/Pipeline/RunCommit`, `Application/Handler/ReplayService`, `Application/Handler/HookDispatcher`, `Application/Handler/RunMetrics`

**Why coupled:** `RunCommit::commit()` does atomic CAS+persistence AND fires replay rebuild, hook dispatch, effect dispatch, and metrics — all in one method. These are best-effort side-effects that shouldn't be entangled with the commit path.

**Dependency category:** Category 2 — Refactoring without contract changes. The `RunCommit` interface (the `commit()` method signature) could stay the same internally while delegating post-commit work.

**Test impact:** `RunCommit` is tested implicitly through pipeline handler tests rather than directly (no `RunCommitTest.php` found). Extracting side-effects would enable a unit test of the atomic commit path without replay/hook/metrics dependencies.

**Deepening approach:** Introduce a `PostCommitPipeline` that receives the committed `RunState` + events and runs replay, hooks, and metrics as a chain. `RunCommit` would call `$this->postCommitPipeline->afterCommit($nextState, $events)` as the last step.

---

### Candidate 6: Extract `AdvanceRunCallback` Factory from Duplicated Closures

**Cluster:** `StartRunHandler`, `ApplyCommandHandler`, `LlmStepResultHandler`, `ToolCallResultHandler`

**Why coupled:** Four handlers contain identical 20-line `followUpAdvanceCallback()` closures that dispatch `AdvanceRun` messages.

**Dependency category:** Category 1 — Pure deduplication. No external contracts change.

**Test impact:** Each handler test that verifies post-commit behavior manually constructs and invokes the closure. A shared `AdvanceRunScheduler` service could be mocked once.

**Deepening approach:** Create `Application/Pipeline/AdvanceRunScheduler` with `scheduleAfterCommit(string $runId, string $reason): callable`. All four handlers replace their private `followUpAdvanceCallback()` with this service.

---

### Candidate 7: Decouple `EventFactory` from `RunState` Construction

**Cluster:** `Domain/Event/EventFactory`, `Domain/Run/RunState`

**Why coupled:** `EventFactory::incrementStateVersion()` constructs a full `RunState` with all 12 parameters, coupling event creation to state shape.

**Dependency category:** Category 1 — Move method to more appropriate owner.

**Test impact:** `EventFactory` tests would no longer need to construct `RunState`. The `incrementStateVersion()` behavior can move to a `RunState::withVersionAndSeq(int $version, int $lastSeq): RunState` factory method, which is more cohesive.

**Deepening approach:** Add `RunState::incrementVersion(int $eventCount): RunState` as a domain method. Remove `EventFactory::incrementStateVersion()`. Update `RunMessageStateTools::incrementStateVersion()` to call the new method directly.

---

## 4. Top Recommendations (Opinionated)

### Recommendation A: Candidates 1 + 2 — Refactor Pipeline Handler Dependencies (Highest Impact)

**Rationale:** These two changes directly reduce the core friction in the busiest part of the codebase — the pipeline handlers. `RunMessageStateTools` is the most used shallow module (referenced by all 5 handlers). Extracting tool orchestration from `LlmStepResultHandler` is the highest-LoC reduction per change.

**What changes:**
1. Delete `RunMessageStateTools`; inject `EventFactory`, `ToolCallExtractor`, `AgentMessageNormalizer` directly into handlers that need them
2. Extract `ToolCallOrchestrator` with `orchestrateToolCalls()` from `LlmStepResultHandler`
3. Each handler's constructor shrinks by 1 param (loses stateTools) and gains 1-2 focused params

**Risk:** Low. No contract changes. Pure internal refactor. Deptrac boundaries unchanged.

**Estimated effort:** 4-6 hours. Tests need constructor signature updates.

---

### Recommendation B: Candidate 4 — Fix Domain/Infrastructure Boundary (Architectural Correctness)

**Rationale:** The Domain layer currently imports Symfony AI types (`AssistantMessage`, `Thinking`, `ToolCall`). This is a latent violation that will cause pain during Symfony AI major version upgrades. Fixing it now prevents future coupling debt.

**What changes:**
1. Move `AgentMessageNormalizer` to `Infrastructure/SymfonyAi/` or `Application/Conversion/`
2. Update all imports in handlers that use `AgentMessageNormalizer` through `RunMessageStateTools` (or directly after Candidate 1)
3. Keep `AgentMessage` (pure domain value object) in `Domain/Message/`

**Risk:** Low-Medium. Import path changes affect several files, but all are in the Application layer which already depends on Infrastructure.

**Estimated effort:** 2-3 hours. Straightforward move + import updates.

---

### Recommendation C: Candidate 3 — Unify CommandMailboxPolicy (Quick Win)

**Rationale:** The 80% code duplication between `applyPendingTurnStartCommands()` and `applyPendingStopBoundaryCommands()` is the clearest case of unnecessary complexity. The two methods are nearly identical; unifying them would remove ~150 lines of duplicated logic and reduce the test surface by roughly half.

**What changes:**
1. Extract shared `applyPendingCommands(RunState $state, BoundaryType $boundary): CommandApplicationResult` private method
2. Both public methods become thin wrappers: `return $this->applyPendingCommands($state, BoundaryType::TurnStart)`
3. Test the unified method once instead of twice

**Risk:** Very low. Behavior is preserved exactly. BoundaryType enum controls the single behavioral difference.

**Estimated effort:** 2-3 hours. Well-understood refactor with existing test coverage.

---

## 5. Dependency Health Summary

| Metric | Value |
|---|---|
| Deptrac violations | 0 |
| Deptrac uncovered (expected) | 591 |
| Deptrac allowed | 856 |
| Files in `src/AgentCore/` | 82 |
| Average file size (pipeline handlers) | 255 lines |
| Average file size (domain) | 60 lines |
| Largest file | ToolExecutor (525 lines) |
| Handler constructor deps (average) | 6.4 |

The architecture is fundamentally sound — clean layers, strong contracts, well-enforced boundaries. The friction points are internal module depth issues, not structural problems. The most impactful changes are Candidates 1 (split the god-class facade) and 2 (extract tool orchestration), both of which deepen modules by reducing interface surface while preserving the same implementation complexity.
