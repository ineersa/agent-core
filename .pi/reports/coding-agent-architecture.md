# CodingAgent Architecture Analysis

**Date**: 2026-06-02  
**Target**: `src/CodingAgent/` namespace (190 PHP files) plus `config/services.yaml` and `tests/CodingAgent/`  
**Method**: Read-only exploration following Ousterhout's "deep module" lens — interface size vs. implementation complexity, testability seams, coupling categories, and architectural friction.

---

## 1. Module Map

| Namespace | Files | Responsibility |
|---|---|---|
| `Runtime/Contract` | 6 | AgentSessionClient interface, runtime DTOs, exception boundary |
| `Runtime/Protocol` | 5 | JSONL codec, RuntimeEvent/RuntimeCommand DTOs, RuntimeEventMapper |
| `Runtime/Mapping` | 5 | 5 subscribers translating AgentCore event strings → RuntimeEvent |
| `Runtime/ProjectionPipeline` | 8 | Transcript projector + 5 projection subscribers + facade |
| `Runtime/Stream` | 7 | LLM stream deltas: 3 stream subscribers + dispatch observer |
| `Runtime/Controller` | 8 | HeadlessController, ConsumerSupervisor, command handlers |
| `Runtime/InProcess` | 2 | In-process agent session client + in-memory event sink |
| `Runtime/Process` | 5 | Process transport: JSONL client, supervisor, executable locator |
| `Runtime/Session` | 1 | TranscriptPersistenceService (controller-mode transcript flushing) |
| `Config` | 16 | AppConfig, loader, settings paths, model/reasoning resolution, tool configs |
| `Config/Ai` | 6 | AI model definitions, catalogs, provider configs, cost metadata |
| `Extension` | 5 | ExtensionManager, loader subscriber, tool registry bridge, hook registry + subscriber |
| `ExtensionApi` | 8 | Public extension API: interfaces, DTOs, enums (stable boundary) |
| `Extension/Builtin/SafeGuard` | 13 | SafeGuard extension: hooks, classifier, policy, config |
| `Tool` | 16 | ToolRegistry, RegistryBackedToolbox, ToolRuntime, tool implementations |
| `Session` | 6 | HatfieldSessionStore, SessionRunStore, SessionRunEventStore, JsonlIdempotencyStore |
| `Skills` | 6 | Skill discovery, registry, context building, rendering |
| `SystemPrompt` | 3 | System prompt builder, AGENTS.md context discovery/rendering |
| `Logging` | 8 | Log reader, log parser, filter, context processor, Monolog handler |
| `Entity` | 6 | Doctrine entities: HatfieldSession, BackgroundProcess, ToolBatchState |
| `CLI` | 4 | AgentCommand, log commands |
| `Infrastructure/SymfonyAi` | 4 | Symfony AI platform factory, model catalog projection, provider registry |
| `EventListener` | 2 | RuntimeExceptionPolicySubscriber, ConsoleErrorSubscriber |

---

## 2. Architecture Boundaries (Deptrac-enforced)

| Layer | Must not depend on |
|---|---|
| `Tui` | AgentCore, CodingAgent internals, Messenger |
| `AgentCore` | CodingAgent, Tui, FrameworkBundle |
| `CodingAgent` | Tui, FrameworkBundle HTTP stack |
| `ExtensionApi` | Everything — must stay pure PHP types |
| `CodingAgent → Tui` | Through `Runtime/Contract` + `Protocol` only |

---

## 3. Exploration Findings — Friction Points

### 3.1 RuntimeEventMapper: Symfony EventDispatcher as internal routing table

**Files**: `Runtime/Protocol/RuntimeEventMapper.php` (79 lines), `Runtime/Mapping/*` (5 files, ~550 lines total)

The `RuntimeEventMapper` dispatches each AgentCore `RunEvent` through Symfony's EventDispatcher using the raw AgentCore event type string as the event name. Five `EventSubscriberInterface` subscribers register for specific event strings and translate them to `RuntimeEvent` DTOs.

**Friction**:
- **"First handler wins" via mutable `$event->handled` flag**: Each subscriber method must check `if ($event->handled) return;` as its first line. Ordering matters: `HitlMappingSubscriber` registers at priority 10 for `agent_command_applied` to beat `CancelAndFallbackMappingSubscriber` at priority 0.
- **Fallback is buried in the mapper, not a subscriber**: Unknown events fall through to `status.updated` with debug metadata — this is in `RuntimeEventMapper::toRuntimeEvent()`, not in the subscriber chain, creating split responsibility.
- **Drop events (`tool_batch_committed`, etc.) are explicit `onDrop` handlers** that set `handled=true` but leave `mappedRuntimeEvent=null`. This is the same as the natural behavior if no subscriber matched, but implemented as explicit handlers.
- **Tests must wire up all 5 subscribers**: `RuntimeEventMapperTest.php` (545 lines) manually instantiates all 5 subscribers and registers them. If a new subscriber is added, every test that creates a mapper must add it, or else raw event types fall through to `status.updated`. This is the Symfony `EventDispatcher` pattern leaking into test setup.
- **Coupling**: Every subscriber depends on both `RunEventMappingEvent` (mutable DTO) and `RuntimeEvent` + `RuntimeEventTypeEnum` — that's 5 files with the same 3 imports.
- **Category**: **Co-ownership of concept** — the "AgentCore event → runtime event" translation table is split across 6 files (mapper + 5 subscribers) when it's fundamentally a single mapping function.

```php
// From HitlMappingSubscriber.php:45 — priority 10 MUST beat CancelAndFallbackMappingSubscriber
public static function getSubscribedEvents(): array
{
    return [
        'waiting_human' => 'onWaitingHuman',
        'agent_command_applied' => ['onAgentCommandApplied', 10],  // ← priority 10
    ];
}
```

### 3.2 ModelSelectionService: Mutating read-side mixed with write-side

**File**: `Config/ModelSelectionService.php` (350 lines)

This service handles four distinct concerns:
1. **Model resolution** — 4-tier priority (explicit → session → default → first-available)
2. **Reasoning resolution** — same 4-tier pattern
3. **Mutation + persistence** — `changeModel()`, `changeReasoning()`, `toggleFavorite()` all write to both `HomeSettingsWriter` (filesystem) and `SessionMetadataStore` (DB)
4. **Favorites management** — get, toggle, cycle, order with in-process cache invalidation

**Friction**:
- Resolution logic is pure (no side effects, no persistence) but lives in the same class as mutation logic that writes to disk/DB. Tests for resolution must mock `AppConfig`, `HomeSettingsWriter`, and `SessionMetadataStore` even though resolution never uses the latter two.
- The `favRaw` in-process cache (line `private ?array $favRaw = null;`) adds mutable state to a service that is otherwise a resolver. `toggleFavorite()` populates this cache so subsequent `getFavoriteModels()` reads see the new state without needing an `AppConfig` rebuild.
- `getSupportedReasoningLevels()` depends on both model resolution and catalog lookup — it's a display concern mixed with resolution.
- **Test impact**: `ModelSelectionServiceTest.php` must construct the full AppConfig → AiConfig → HatfieldModelCatalog chain or use heavy mocking to test what is fundamentally string matching logic.

### 3.3 HeadlessController: 650-line God controller

**File**: `Runtime/Controller/HeadlessController.php` (650 lines)

Manages:
- stdin reading loop with Revolt `EventLoop::onReadable()`
- LLM stdout polling with partial-line buffering (10ms interval)
- Canonical event drain from `InProcessAgentSessionClient` (50ms interval)
- Consumer supervision via `ConsumerSupervisor` (5s interval)
- Signal handling (SIGTERM, SIGINT)
- Command dispatch through Symfony `EventDispatcherInterface`
- Transcript persistence via `TranscriptPersistenceService`
- Orphan consumer cleanup
- Shutdown orchestration

**Friction**:
- 8 constructor dependencies; 3 event loop timers with different intervals that interact (event drain feeds transcript persistence, LLM stdout feeds stream subscribers)
- The partial-line buffer logic for LLM stdout (`$llmStdoutBuffer`) mirrors the same pattern in `JsonlProcessAgentSessionClient` — code duplication for the same concern.
- `killOrphanedConsumers()` (60 lines) is process-management logic inside the controller, not in `ConsumerSupervisor`.
- `feedPersister()` + `persistTranscripts()` are thin wrappers on `TranscriptPersistenceService` — could be extracted.
- **Test impact**: `ControllerSmokeTest` is the only direct test (real E2E with `proc_open`, `llama.cpp`). There are no unit tests for the event loop logic, stdin parsing, or stdout buffering.

### 3.4 ExtensionToolHookEventSubscriber: Extension hooks coupled to Symfony AI events

**File**: `Extension/ExtensionToolHookEventSubscriber.php` (230 lines)

Extension tool hooks (`ToolCallHookInterface`, `ToolResultHookInterface`) are dispatched by subscribing to Symfony AI's internal toolbox lifecycle events: `ToolCallRequested`, `ToolCallSucceeded`, `ToolCallFailed`.

**Friction**:
- Extension hooks can only fire inside the Symfony AI `ToolboxInterface::execute()` path. If a tool were executed through a different mechanism (direct handler, test harness, future non-Symfony-AI backend), hooks would silently not fire.
- The `onToolCallRequested` handler (85 lines) manipulates `ToolCallRequested::setResult()` directly — this is low-level Symfony AI event manipulation, not a clean policy interface.
- The approval flow (`RequireApproval`) creates a pending approval in `ExtensionHookRegistry`, but the answer routing happens through a separate path (`ExtensionApprovalAnswerSubscriber`) — split lifecycle management.
- The public `ExtensionApiInterface` exposes `registerToolCallHook()` and `registerToolResultHook()`, but the actual dispatch is through internal Symfony AI events — the abstraction leaks.

### 3.5 Shallow Config DTO factories

**Files**: `LoggingConfig.php`, `OutputCapConfig.php`, `ImageToolConfig.php`, `BackgroundProcessConfig.php`, `ToolSettings.php`, `CodingAgentImageCapabilityChecker.php`

Each of these uses a static `fromAppConfig(AppConfig)` factory that unpacks typed fields from the merged config array:

```yaml
# From config/services.yaml — each config DTO needs its own factory definition
Ineersa\CodingAgent\Config\LoggingConfig:
  factory: ['Ineersa\CodingAgent\Config\LoggingConfig', 'fromAppConfig']
  arguments:
    - '@Ineersa\CodingAgent\Config\AppConfig'

Ineersa\CodingAgent\Config\OutputCapConfig:
  factory: ['Ineersa\CodingAgent\Config\OutputCapConfig', 'fromAppConfig']
  arguments:
    - '@Ineersa\CodingAgent\Config\AppConfig'
# ... 4 more identical patterns
```

**Friction**: These DTOs are immutable value objects with trivial constructors. The `fromAppConfig()` method is essentially a field extraction — 10-15 lines of `$appConfig->tools->outputCap->enabled ?? true`. The DI overhead is proportional: each needs a named service definition with explicit factory wiring. Test setup cascades — any service depending on `OutputCapConfig` needs an `AppConfig` → `ToolsConfig` chain.

### 3.6 Settings path resolution: manual, non-extensible

**File**: `Config/AppConfigLoader.php` lines 170-213 (`resolveConfigPaths()`)

Five hardcoded `if` blocks resolve path placeholders for specific config sections:

```php
// From AppConfigLoader.php:170-213
if (isset($data['tui']['theme_paths']) && is_array($data['tui']['theme_paths'])) { ... }
if (isset($data['sessions']['path']) && is_string($data['sessions']['path'])) { ... }
if (isset($data['logging']['path']) && is_string($data['logging']['path'])) { ... }
if (isset($data['tools']['output_cap']['path']) && is_string(...)) { ... }
if (isset($data['tools']['background_process']['path']) && is_string(...)) { ... }
```

Adding a new config section with a path requires adding another `if` block. The pattern is identical each time: check key exists → resolve → replace.

---

## 4. Candidate List

### Candidate 1: Collapse RuntimeEventMapper subscriber chain into a single well-structured mapper

| Field | Detail |
|---|---|
| **Cluster** | `Runtime/Protocol/RuntimeEventMapper`, `Runtime/Mapping/*` (5 subscribers), `Runtime/Protocol/RunEventMappingEvent` |
| **Why coupled** | 5 subscribers + 1 mapper co-own the AgentCore→RuntimeEvent translation table. Subscription priority determines correctness. The mutable `handled` flag is the handoff protocol. |
| **Dependency category** | **Co-ownership of concept** — the translation logic is split across 6 files but constitutes a single conceptual mapper. |
| **Test impact** | `RuntimeEventMapperTest.php` (545 lines) must manually wire all 5 subscribers. New event type → must update both a subscriber AND the test setup. Replacing with a single class reduces test surface to constructor + 1 method. |
| **Friction depth** | The 5 subscribers average 100 lines each. Total: ~550 lines + 80-line mapper = ~630 lines. A single file with a dispatch table (event_type → handler callable) would be ~200-250 lines. |
| **Risk** | Low — the mapping logic is already well-tested (the test covers all event families). The refactor is purely internal — the `RuntimeEventMapper` public API (`toRuntimeEvent()`, `toRunEventData()`) stays unchanged. |

### Candidate 2: Separate ModelSelectionService into read-side resolution and write-side persistence

| Field | Detail |
|---|---|
| **Cluster** | `Config/ModelSelectionService`, `Config/HomeSettingsWriter`, `Config/SessionMetadataStore`, `Config/Ai/HatfieldModelCatalog` |
| **Why coupled** | Model resolution (pure logic) and model mutation (persistence side effects) live in the same class. The `favRaw` in-process cache adds mutable state to the resolver. |
| **Dependency category** | **Mixed concerns** — read-side query logic (resolution) vs. write-side command logic (persistence) vs. cache management (favorites). |
| **Test impact** | `ModelSelectionServiceTest.php` currently needs the full `AppConfig` → `AiConfig` → `HatfieldModelCatalog` chain for every resolution test. Extracting a `ModelResolver` (pure logic, no dependencies beyond the catalog) would make ~60% of tests trivial. |
| **Friction depth** | ~350 lines could become: `ModelResolver` (~120 lines, resolution only) + `ModelSettingsPersister` (~80 lines, write-only) + `ModelSelectionService` (~150 lines, coordinates both + favorites). |
| **Risk** | Medium — `ModelSelectionService` is injected into TUI listeners and the controller. The interface surface must stay compatible or have a clear migration path. |

### Candidate 3: Extract event drain and stdout polling from HeadlessController into standalone poller services

| Field | Detail |
|---|---|
| **Cluster** | `Runtime/Controller/HeadlessController`, `Runtime/Controller/ConsumerSupervisor`, `Runtime/Session/TranscriptPersistenceService`, `Runtime/Process/JsonlProcessAgentSessionClient` |
| **Why coupled** | The controller owns the event loop, but the loop itself is plumbing (timers + I/O reading). The business logic is in the timer callbacks (drain events → map → emit → persist; poll stdout → parse JSONL → emit streaming events). |
| **Dependency category** | **Monolithic orchestrator** — the controller is a procedural event loop with 3 timer intervals, each doing a specific job. Those jobs are coherent enough to be standalone services. |
| **Test impact** | Currently zero unit test coverage for the controller (only E2E `ControllerSmokeTest`). Extracting `EventDrainPoller` and `StdoutStreamPoller` would create testable units that take an output callback and an `InProcessAgentSessionClient` / `Process` object. |
| **Friction depth** | The `pollLlmStdout()` method (80+ lines) has its own partial-line buffer, JSONL parsing, and error threshold logic. The event drain timer (60+ lines) manages per-run seq cursors and delegates to `TranscriptPersistenceService`. Both are self-contained enough to extract. |
| **Risk** | Medium — the controller is the entry point for `--controller` mode. Any extraction must preserve the exact event ordering (transient deltas before canonical events) and the partial-line buffering behavior. |

### Candidate 4: Introduce a ToolExecutionHookDispatcher to bridge ExtensionApi hooks and the toolbox

| Field | Detail |
|---|---|
| **Cluster** | `Extension/ExtensionToolHookEventSubscriber`, `Extension/ExtensionHookRegistry`, `ExtensionApi/ToolCallHookInterface`, `Symfony\AI\Agent\Toolbox\Event\*` |
| **Why coupled** | Extension hook dispatch is hard-wired to Symfony AI toolbox lifecycle events. The `ExtensionHookRegistry` is pure, but `ExtensionToolHookEventSubscriber` translates Symfony AI events → hook calls. If tool execution bypasses the Symfony AI toolbox, hooks are skipped. |
| **Dependency category** | **Platform-dependent dispatch** — the extension policy layer (hooks) should be callable regardless of which toolbox/executor is active. |
| **Test impact** | `ExtensionToolHookEventSubscriberTest.php` (411 lines) must construct Symfony AI `ToolCallRequested` events with `ToolCall` objects to test hook dispatch. A dedicated dispatcher would test against the ExtensionApi DTOs directly. |
| **Friction depth** | The subscriber is 230 lines. A `ToolExecutionHookDispatcher` that takes plain DTOs and dispatches through `ExtensionHookRegistry` would be ~80 lines. The Symfony AI subscriber becomes a thin adapter. |
| **Risk** | Low — the hook registry and DTOs are already decoupled. The subscriber just needs a new class that calls into it from both Symfony AI and any future execution path. |

### Candidate 5: Path resolution in AppConfigLoader via declarative path-map instead of hardcoded if-blocks

| Field | Detail |
|---|---|
| **Cluster** | `Config/AppConfigLoader` (resolveConfigPaths method) |
| **Why coupled** | Five identical `if` blocks for five config sections. Adding a new path-bearing section requires modifying a 200-line class. |
| **Dependency category** | **Non-extensible hardcoded dispatch** — the pattern is repetitive and manual. |
| **Test impact** | Minimal — `AppConfigLoaderTest` tests the overlay logic; path resolution is currently a side effect. Making it declarative adds a test for the path-map itself. |
| **Friction depth** | 40 lines → 10 lines with a configurable path-key map. |
| **Risk** | Very low — internal implementation detail, no public API change. |

---

## 5. Top 3 Recommendations

### Recommendation 1: Collapse RuntimeEventMapper subscribers into a single mapper (Candidate 1)

**Why this is the strongest candidate**:

The current design uses Symfony's EventDispatcher for a problem that doesn't benefit from it. The 5 subscribers implement a **translation table** — a deterministic mapping from AgentCore event type strings to RuntimeEvent DTOs. There is no runtime extension of this mapping, no dynamic subscriber registration, and the mapping logic is purely internal to CodingAgent (no cross-boundary dispatch).

The "first handler wins" pattern via `$event->handled` is a workaround for using an event dispatcher where a simple `match` or registry would suffice. The priority-based ordering between `HitlMappingSubscriber` (priority 10) and `CancelAndFallbackMappingSubscriber` (priority 0) for the same event type (`agent_command_applied`) is a silent correctness dependency — if priorities were swapped, HITL responses would be mapped to `status.updated` instead of `human_input.answered`.

A single `RuntimeEventTranslator` class with a dispatch table would:
- Reduce 6 files (630 lines) to 1 file (~250 lines)
- Eliminate the need for `$event->handled` checks in every handler
- Make event-type → RuntimeEventType mapping visible in one place (currently spread across 5 `getSubscribedEvents()` methods)
- Simplify tests from 545 lines of subscriber wiring to testing a single class
- Preserve the `RuntimeEventMapper` public API unchanged (the `toRuntimeEvent()` method signature stays)

**Testability gain**: The mapper test no longer needs to construct an `EventDispatcher` and register subscribers manually. A single test class with `testMaps($agentCoreType, $agentCorePayload, $expectedRuntimeEventType)` is sufficient.

### Recommendation 2: Separate ModelSelectionService resolver and persister (Candidate 2)

**Why this is the second strongest**:

The `ModelSelectionService` is the most "shallow module" in the Config namespace — its 350-line interface is almost as complex as its implementation. The resolution logic is pure (string matching against a catalog), but it's entangled with filesystem writes, DB updates, and an in-process cache.

Extracting a `ModelResolver` (read-only) and `ModelSettingsPersister` (write-only) would:
- Make resolution logic trivially testable with just a `HatfieldModelCatalog` (no mocks needed)
- Make persistence logic testable with `HomeSettingsWriter` + `SessionMetadataStore` mocks (no catalog needed)
- Keep the `ModelSelectionService` as a thin coordinator for callers that need both (TUI listeners, controller)
- Eliminate the `favRaw` mutable cache from the resolver

**Testability gain**: The 4-tier priority resolution can be tested with 4 catalog states and zero persistence dependencies. Currently `ModelSelectionServiceTest` needs a full `AppConfig` for what should be a pure function test.

### Recommendation 3: Extract event drain and stdout polling from HeadlessController (Candidate 3)

**Why this is third**:

The HeadlessController's complexity is partly inherent (it IS the event loop), but the timer callbacks are self-contained services that happen to share an event loop. Extracting them creates testable units and reduces the controller to wiring + lifecycle management.

Specifically, extracting `EventDrainPoller` (reads canonical events from InProcessAgentSessionClient, maps them, emits them) and `StdoutStreamPoller` (reads LLM stdout, parses JSONL, emits streaming events) would:
- Allow testing event drain logic without spawning a full controller process
- Allow testing stdout buffering/parsing with a mock stream
- Eliminate the duplicated partial-line buffering pattern (same logic in `JsonlProcessAgentSessionClient`)
- Reduce the controller from ~650 lines to ~300 lines

**Testability gain**: The event drain loop and stdout parser currently have zero unit test coverage. Extraction creates two new testable units that cover the core controller I/O logic.

---

## 6. What's Already Well-Structured

Several areas deserve explicit recognition for good architecture:

- **ExtensionApi boundary**: The 8 files in `ExtensionApi/` are pure PHP types (interfaces, DTOs, enums) with zero dependencies on CodingAgent internals. `ExtensionToolRegistryBridge` cleanly adapts this public boundary to the internal `ToolRegistryInterface`. Deptrac enforces the no-inward-dependency rule.

- **Runtime Contract interfaces**: `AgentSessionClient`, `TranscriptProjectorInterface`, `RuntimeEventSinkInterface` provide clear seams between TUI and runtime. The `InProcessAgentSessionClient` and `JsonlProcessAgentSessionClient` implement the same interface with different transports — the classic port/adapter pattern.

- **ToolRegistry + RegistryBackedToolbox**: The registry manages permanent/dynamic tools with deterministic ordering and deduplication. `RegistryBackedToolbox` is a thin adapter to Symfony AI's `ToolboxInterface`. The separation is clean — registry owns the data, toolbox owns the execution dispatch.

- **TranscriptProjector facade + subscribers**: The `TranscriptProjector` exposes a minimal interface (`accept(array)`, `blocks()`, `reset()`) and delegates to 5 family-specific projection subscribers via Symfony EventDispatcher. This is the same pattern as `RuntimeEventMapper` but is more justified because the projection is genuinely extensible (TUI extensions can add projection subscribers).

- **Session store isolation**: `HatfieldSessionStore` owns the DB row + filesystem directory, but `SessionRunStore` and `SessionRunEventStore` handle in-process/JSONL event persistence. The lock-based concurrency control is explicit and well-documented.

- **AppConfig layering**: The three-layer overlay (defaults → home → project) with associative deep-merge and list-replacement semantics is well-documented, correctly handled, and independently tested in `AppConfigLoaderTest`.

---

## 7. Dependency Heatmap

```
High coupling zones:
  ┌──────────────────────┐
  │  HeadlessController  │──▶ ConsumerSupervisor, TranscriptPersistenceService,
  │  (8 deps, 650 LOC)   │    InProcessAgentSessionClient, EventDispatcher,
  │                      │    BackgroundProcessManager, RuntimeExceptionBoundary
  └──────────┬───────────┘
             │
  ┌──────────▼───────────┐
  │ InProcessAgentSession│──▶ AgentRunnerInterface, EventStoreInterface,
  │ Client (120 LOC)     │    RuntimeEventMapper, SystemPromptBuilder,
  │                      │    AgentsContextDiscovery/Renderer, SkillsContextBuilder
  └──────────────────────┘

  ┌──────────────────────┐
  │  ModelSelectionService│──▶ AppConfig, HomeSettingsWriter,
  │  (350 LOC)           │    SessionMetadataStore (reads + writes + cache)
  └──────────────────────┘

  ┌──────────────────────┐
  │ ExtensionToolHook    │──▶ ExtensionHookRegistry, Symfony\AI\Toolbox\Event\*
  │ EventSubscriber      │    StackToolExecutionContextAccessor
  │ (230 LOC)            │
  └──────────────────────┘

Low coupling zones:
  ExtensionApi/* (8 files) — zero internal deps
  ToolRegistryInterface — narrow, focused contract
  AgentSessionClient — clean TUI boundary
```
