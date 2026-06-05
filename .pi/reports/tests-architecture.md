# Test Architecture Report

**Generated:** 2026-06-02  
**Scope:** `tests/AgentCore`, `tests/CodingAgent`, `tests/Tui`  
**Method:** Read-only exploration — no production files modified

---

## 1. Structural Overview

### Size & Distribution

| Module | Test files | Test lines (est.) | Source files | Ratio |
|--------|-----------|-------------------|-------------|-------|
| AgentCore | 37 | ~8,500 | 141 | 1:3.8 |
| CodingAgent | 68 | ~16,000 | 190 | 1:2.8 |
| TUI | 28 | ~7,000 | 79 | 1:2.8 |
| **Total** | **133** | **~31,100** | **410** | **1:3.1** |

### Test Categories by Base Class

| Base class | Count | Purpose |
|-----------|-------|---------|
| `PHPUnit\Framework\TestCase` | 95+ | Pure unit tests (no kernel boot) |
| `KernelTestCase` (direct) | 4 | Kernel-booted integration tests without CWD isolation |
| `IsolatedKernelTestCase` | 4 | Kernel-booted + isolated CWD + test DB |
| `ControllerE2eTestCase` | 3 | Process-spawning E2E smoke tests |
| `TestCase` (TUI E2E + TmuxHarness) | 2 | Tmux-snapshot TUI E2E tests |

### Test Groups

| Group | Tests | Castor Command | Requires |
|-------|-------|---------------|----------|
| `llm-real` | 5 (ControllerE2E + LlamaCppSmokeTest + ViewImageE2E + WriteFileE2E + TuiAgentSmokeTest) | `castor test:llm-real` | llama.cpp on port 9052 |
| `tui-e2e` | 3 (TuiStartupSnapshotTest ×2 + TuiAgentSmokeTest) | `castor test:tui` | tmux, llama.cpp |

---

## 2. Strengths

### 2.1 Architecture-Aligned Test Organization

Tests mirror the source module structure exactly. The three test suites in `phpunit.xml.dist` (`agent-core`, `coding-agent`, `tui`) match the three source modules. Architecture boundaries from `depfile.yaml` are implicitly tested because tests import from the same namespaces.

### 2.2 Excellent E2E Infrastructure

**Controller E2E (`ControllerE2eTestCase`):**
- Spawns `bin/console agent --controller` via `proc_open` with isolated per-test `var/tmp/` directories
- JSONL protocol over stdin/stdout pipes — production-realistic protocol
- Non-blocking stderr drain, buffer-based event parsing, timeout-gated event collection
- Rich diagnostics: dumps all collected events, session artifacts (`state.json`, `events.jsonl`, `transcript.jsonl`), messenger DB state on failure
- Overridable `modelConfig()` for capabilities (text-only vs text+image, tool-calling)
- `tempDirPrefix()` for per-test-class isolation namespacing

**TUI E2E (`TmuxHarness`):**
- Full tmux session management: start detached, send literal text/keystrokes, poll for content
- Three capture modes: `capturePlain()`, `capturePlainWithHistory()`, `captureAnsi()`
- Three polling strategies: `waitForCaptureContains()`, `waitForHistoryContains()`, `waitForCallback()`
- Snapshot normalization: UUIDs → `<run-id>`, absolute paths → `<root>`, footer segments (CWD, branch) normalized
- Defensive timeout/error handling: catches `RuntimeException` from polling, falls through to diagnostic dump before failing

### 2.3 Correct DB Test Isolation

- `DAMA/DoctrineTestBundle` with static connection + transaction rollback (`dama_doctrine_test.yaml`)
- Fixed test SQLite path (`var/test/app_test.sqlite`) — independent of CWD
- `services_test.yaml` cleanly removes the production `registerShutdownHandler()` call on `BackgroundProcessManager`
- In-memory Messenger transports (`config/packages/test/messenger.yaml`) prevent test DB from polluting message queues
- `KernelTestCase` auto-resets in-memory transports between tests

### 2.4 Well-Structured Test Doubles

- `FakeToolExecutor` (`tests/AgentCore/Support/Fake/`): implements `ToolExecutorInterface`, records calls, supports per-tool-name handlers — `35 lines`
- `FakePlatform` (`tests/AgentCore/Support/Fake/`): implements `PlatformInterface`, queued push/pop responses, records invocations — `58 lines`
- `TestSerializerFactory`: real Symfony Serializer with metadata-aware name converter for DTO round-trip tests
- `SymfonyAiTestMessages`: shared message factory for AI integration tests

### 2.5 Thorough Session/Event Store Tests

- JSONL corruption detection with required-field validation (`SessionRunEventStoreTest:testCorruptJsonLine...`)
- Embedded run ID integrity validation (`testEmbeddedRunIdMustMatchDirectory`)
- Incompatible schema version handling with documented skip policy (`testIncompatibleSchemaVersionIsSkippedWithDiagnosticPolicy`)
- Cross-store survival (`testEventsSurviveStoreRecreation`)
- Run isolation and multi-event sorted retrieval

### 2.6 Contract Tests for Lifecycle Event Ordering

`LifecycleEventContractTest` (`tests/AgentCore/Contract/`) tests `CoreLifecycleEventType::validateOrder()` with data-provider-driven scenarios: prompt, continue, tools, steering, follow-up, cancel — plus extension event insertion at valid boundary points and rejection at invalid points (cannot cross assistant→tool barrier).

### 2.7 No Architecture Violations in Test Code

- Zero uses of `Closure::bind()`, `ReflectionClass::newInstanceWithoutConstructor()`, or constructor bypass tricks in tests
- Single legitimate `ReflectionClass` use in `IsolatedKernelTestCase::getProjectDirFromKernel()` to read the Kernel class file location — not a bypass
- `ProjectDir::get()` resolves the project root through `Kernel::getProjectDir()` which walks up to find `composer.json` — robust and test-move-safe

---

## 3. Friction Points

### 3.1 Domain Value Objects Tested Only Indirectly (Shallow Coverage)

**Evidence:**  
`src/AgentCore/Domain/Run/RunState.php` — central domain object with status transition logic, streaming state management, pending tool call tracking. Never tested directly. Tested only as input/output to handler tests like `AdvanceRunHandlerTest` and `StartRunHandlerTest`.

Similarly untested at the domain boundary:
- `src/AgentCore/Domain/Tool/ToolCall.php`, `ToolResult.php`, `ToolExecutionMode.php`, `ToolExecutionPolicy.php`
- `src/AgentCore/Domain/Command/CoreCommandKind.php`, `PendingCommand.php`, `RoutedCommand.php`
- `src/AgentCore/Domain/Model/ModelInvocationRequest.php`, `PlatformInvocationResult.php`, `ModelInvocationOptions.php`
- `src/AgentCore/Domain/Run/RunMetadata.php`, `PromptState.php`, `StartRunInput.php`
- `src/AgentCore/Domain/Extension/AfterTurnCommitEventSummary.php`, `CommandCancellationOptions.php`

**Risk:** Handler tests evolve with pipeline changes; domain invariants (e.g., `RunState` must not transition from `Completed` to `Running`, `ToolCall.orderIndex` must be non-negative) have no dedicated assertion home. A future handler refactor can silently break a domain rule.

**Files with zero corresponding test directories:**
```
src/AgentCore/Domain/Event/         (has LifecycleEventContractTest but no Event/ dir)
src/AgentCore/Domain/Run/
src/AgentCore/Domain/Command/
src/AgentCore/Domain/Tool/
src/AgentCore/Domain/Model/
src/AgentCore/Domain/Extension/
src/AgentCore/Contract/Tool/
src/AgentCore/Contract/Model/
src/AgentCore/Contract/Hook/
src/AgentCore/Contract/Extension/
```

### 3.2 Untested Async Runtime Infrastructure

**Evidence:** The following production directories have no corresponding test directories and no unit tests:

| Untested Directory | Contents | Risk |
|---|---|---|
| `src/CodingAgent/Runtime/Protocol/` | JSONL command/event DTOs, codec | Protocol breaking changes invisible |
| `src/CodingAgent/Runtime/Controller/Event/` | Controller event dispatching | Event ordering bugs only caught in E2E |
| `src/CodingAgent/Runtime/Controller/CommandHandler/` | Per-command handler dispatch | New commands can break routing |
| `src/CodingAgent/Runtime/Session/` | Runtime session management | Session lifecycle bugs only in E2E |
| `src/CodingAgent/Runtime/ProjectionPipeline/` | Event→projection pipeline | Projection ordering bugs only in E2E |
| `src/CodingAgent/Runtime/Process/` | Subprocess runtime implementation | Process-spawning bugs only in E2E |
| `src/CodingAgent/Runtime/Mapping/` | Event mapping/transformation | Mapping errors only in E2E |

These are all exercised by `ControllerSmokeTest`/`WriteFileToolE2eTest`/`ViewImageToolE2eTest`, but failure diagnostics from those tests are "run didn't complete" — no fine-grained signal about which component failed.

### 3.3 Untested TUI Runtime/Screen/Application Layer

**Evidence:** Zero test files for:
```
src/Tui/Application/InteractiveMode.php      ← main TUI entry point
src/Tui/Application/SessionInitializer.php
src/Tui/Application/ThemeFactory.php
src/Tui/Screen/ChatScreen.php                ← primary screen layout
src/Tui/Runtime/RuntimeEventPoller.php        ← event loop integration
src/Tui/Runtime/TuiSessionState.php
src/Tui/Runtime/TuiTickDispatcher.php
src/Tui/Runtime/TuiRuntimeContext.php
src/Tui/Header/HeaderWidget.php              ← header rendering
src/Tui/Status/StatusPanelWidget.php
src/Tui/Status/WorkingStatusWidget.php
```

Only the `TuiAgentSmokeTest` (tmux E2E) exercises these. A widget initialization bug, event polling race condition, or screen layout change would only be caught by the slow tmux smoke test.

### 3.4 Single Golden Snapshot — Invisible Layout Regressions

**Evidence:** `tests/Tui/Snapshots/` contains only `startup-120x40.txt`. The `TuiAgentSmokeTest` does not use golden snapshots — it asserts presence of `◇` or `✕` characters, then manually checks for user prompt text. Layout regressions (widget misplacement, footer truncation, color changes) are invisible.

### 3.5 No Builder/Test-Data-Factory Pattern

**Evidence:** Every handler test constructs `RunState` inline with 8-10 named parameters. Example from a single test:

```php
// AdvanceRunHandlerTest.php:47
$state = new RunState(
    runId: 'run-advance-handler-1',
    status: RunStatus::Running,
    version: 7,
    turnNo: 2,
    lastSeq: 11,
    activeStepId: 'turn-2-step',
);
```

`StartRun`, `AdvanceRun`, `ExecuteLlmStep`, `ToolCall`, `ToolCallResult` — all constructed inline with full parameter lists across dozens of tests. Parameter defaults and validation rules are implicitly duplicated. When `RunState` gains a new required field, 30+ test setups break.

### 3.6 TranscriptProjectorTest: Overgrown Boundary Test (1161 lines)

The largest test file at 1161 lines. It tests transcript projection logic with a mix of unit tests, snapshot-style output assertions, and integration flows. Hard to navigate, hard to know which tests cover which projection cases.

### 3.7 Three Competing Filesystem Isolation Patterns

| Pattern | Used By | Directory |
|---------|---------|-----------|
| `IsolatedKernelTestCase` with `chdir()` | HatfieldSessionStoreTest, DbalToolBatchStoreTest, BackgroundProcessManagerTest, BgStatusToolTest | `var/tests/hatfield-test-<hex>/` |
| `sys_get_temp_dir()` + manual `mkdir` | SessionRunEventStoreTest, AggregateResumeTest, ThemeRegistryTest, EditFileToolTest | `/tmp/hatfield-*-<pid>/` |
| `var/tmp/<prefix>-<uniqid>` | ControllerE2eTestCase, TuiStartupSnapshotTest, TuiAgentSmokeTest | `var/tmp/<prefix>-<uniqid>/` |

No consistency. If the CWD-isolation invariant changes, three code paths need updating.

### 3.8 Over-Mocked Registry Tests

**`SlashCommandRegistryTest`** (`tests/Tui/Command/SlashCommandRegistryTest.php`): 18 calls to `$this->createMockHandler()` creating fresh anonymous `SlashCommandHandler` mocks — even in tests that only assert registration metadata (name, aliases, has/getMetadata). A null-object implementing `SlashCommandHandler` would serve all these tests without mock setup overhead.

**`WorkerFailedEventSubscriberTest`** (`tests/AgentCore/Infrastructure/Messenger/WorkerFailedEventSubscriberTest.php`): 16 mock creations for `RunStoreInterface` and `EventStoreInterface` — each test method creates its own expectations even when the test body only needs "no interaction" verification.

### 3.9 ExtensionApi: No Boundary Test

**Evidence:** The public API surface (`Ineersa\Hatfield\ExtensionApi` namespace) has 13 files (DTOs, enums, interfaces) with zero dedicated test files. It is tested only through `ExtensionManagerTest` (596 lines). No test verifies:
- DTO immutability (all `readonly` properties)
- Serialization round-trip of `ToolRegistrationDTO`, `ToolCallDecisionDTO`, etc.
- Namespace independence (confirmed clean: zero imports from CodingAgent/AgentCore/TUI/Symfony/Doctrine)
- Enum value stability (`ToolCallDecisionKindEnum`, `ToolResultDecisionKindEnum`)

### 3.10 EditFileToolTest System Dependency

**Evidence:** `EditFileToolTest::createUnifiedDiff()` shells out to `/usr/bin/diff -u` (`tests/CodingAgent/Tool/EditFileToolTest.php:377`). The test requires `diff` to be available on the system PATH. Failure mode unclear if `/usr/bin/diff` doesn't exist.

### 3.11 ImageAttachmentProcessorTest: Conditional Skip Fragility

8 calls to `$this->markTestSkipped()` for GD/Imagick availability (`tests/CodingAgent/Tool/ImageProcessing/ImageAttachmentProcessorTest.php`). Some assertions are never exercised in CI if image processing libraries are missing.

---

## 4. Candidate List

### Candidate 1: Domain Value Object Boundary Tests
- **Cluster:** `src/AgentCore/Domain/{Run,Tool,Command,Model,Extension}` + `src/AgentCore/Contract/{Tool,Model,Hook,Extension}`
- **Coupling:** All domain VOs share the same construction/serialization patterns and are consumed by Application handlers. Currently tested only through handler tests.
- **Dependency category:** Pure domain — no external dependencies
- **Test impact:** Add ~15-20 new test files. Existing handler tests still pass. New tests catch regressions that handler tests miss.

### Candidate 2: Runtime Infrastructure Unit Tests
- **Cluster:** `src/CodingAgent/Runtime/{Protocol,Controller/Event,Controller/CommandHandler,Session,ProjectionPipeline,Process,Mapping}`
- **Coupling:** Tightly coupled to Messenger/DTOs/JSONL protocol. Need fake transports for isolation.
- **Dependency category:** Runtime boundary — depends on domain contracts + Messenger + Symfony Serializer
- **Test impact:** Add ~10-15 new test files. E2E tests remain as integration smoke but unit tests provide fast feedback for error paths.

### Candidate 3: TUI Runtime/Application Component Tests
- **Cluster:** `src/Tui/{Application,Screen,Runtime,Status,Header}`
- **Coupling:** Depends on TUI widgets, runtime context, event poller, terminal abstraction
- **Dependency category:** TUI boundary — depends only on TUI internals + CodingAgent Runtime/Contract
- **Test impact:** Add ~10-12 new test files. Fast feedback for layout, widget, and event-polling bugs.

### Candidate 4: Test Data Builder Pattern
- **Cluster:** `tests/` — all test files that construct domain objects
- **Coupling:** Shared construction of `RunState`, `StartRun`, `AdvanceRun`, `ToolCall`, etc. across 30+ test files
- **Dependency category:** Test infrastructure — no production impact
- **Test impact:** Refactors test setup duplication. Makes adding required fields to domain objects a single-builder update.

### Candidate 5: ExtensionApi Boundary Test Suite
- **Cluster:** `src/CodingAgent/ExtensionApi/` (13 files)
- **Coupling:** Must remain namespace-pure (no CodingAgent/AgentCore/Symfony imports). Currently confirmed clean.
- **Dependency category:** Public API surface — must not depend on anything outside `Ineersa\Hatfield\ExtensionApi`
- **Test impact:** Add 1-2 test files verifying DTO immutability, serialization, and interface contract stability.

### Candidate 6: Unify Filesystem Isolation Strategy
- **Cluster:** `IsolatedKernelTestCase`, `SessionRunEventStoreTest`, `ControllerE2eTestCase`, TUI E2E tests
- **Coupling:** Three competing patterns for creating isolated test directories
- **Dependency category:** Test infrastructure — no production impact
- **Test impact:** Consolidate to a single `TestDirectoryIsolation` helper or make `IsolatedKernelTestCase` the universal pattern. Reduces maintenance burden when isolation requirements change.

### Candidate 7: Deepen SlashCommandRegistry Interface
- **Cluster:** `src/Tui/Command/SlashCommandRegistry` + `tests/Tui/Command/SlashCommandRegistryTest`
- **Coupling:** Registry requires both `CommandMetadata` and `SlashCommandHandler` for registration. Tests that only assert metadata still need mock handlers.
- **Dependency category:** TUI module — internal interface
- **Test impact:** Reduce 18 mock creations to 0 by introducing a `NoOpCommandHandler` or separating metadata registration from handler binding.

---

## 5. Top 3 Recommendations

### Recommendation 1: Add Domain Value Object Boundary Tests (Candidate 1)

**Why this matters most:** The domain layer is the foundation of every architecture boundary. `RunState`, `ToolCall`, `ModelInvocationRequest`, and the contract interfaces define the invariants that handlers, runtime, and TUI all depend on. Testing them through handlers is like testing the foundation through the walls — you see the cracks only after the house shifts.

**What to test:**
- `RunState` transition legality (e.g., cannot transition from `Completed` to `Running`)
- `ToolCall`/`ToolResult` construction invariants (orderIndex ≥ 0, required fields)
- `ModelInvocationRequest` → `PlatformInvocationResult` contract
- `AgentMessage` content type validation
- `CoreLifecycleEventType::validateOrder()` edge cases (already started in `LifecycleEventContractTest` — extend)
- Contract interface method signatures (no accidental parameter reordering)

**Files: ~15-20 new test files, ~2000-3000 lines of test code. Zero production changes.**

### Recommendation 2: Introduce Test Data Builders (Candidate 4)

**Why this matters second:** Before adding more tests (Candidates 1-3), reduce the friction of writing them. The inline `new RunState(...)` pattern with 8-10 named parameters is a tax on every new test. Builders make test intent explicit (`RunStateBuilder::running()->withTurn(3)->build()`) and centralize default values.

**Proposed builders:**
- `RunStateBuilder` — status, version, turnNo defaults; chainable
- `StartRunMessageBuilder` — runId, payload, idempotencyKey defaults
- `AdvanceRunMessageBuilder` — runId, turnNo, stepId defaults
- `ToolCallBuilder` — arguments, orderIndex defaults
- `ToolCallResultBuilder` — content array defaults

**Files: Create `tests/Support/Builder/` with 5-8 builder classes. Update existing tests incrementally — builders coexist with inline construction.**

### Recommendation 3: Unify Filesystem Isolation (Candidate 6)

**Why this matters third:** Three competing patterns create a maintenance hazard. When the CWD-isolation requirement changes (e.g., `.hatfield/` directory layout changes, `HATFIELD_CWD` env var semantics change), three code paths break independently. Consolidating makes the system more robust.

**Proposed approach:**
1. Extract `IsolatedKernelTestCase`'s directory management into a standalone `TestDirectoryIsolation` utility (no kernel boot dependency)
2. Make `ControllerE2eTestCase` and TUI E2E tests use the same utility for directory creation
3. Provide `cleanup()` as a separate callable so non-Lifecycle tests can use try/finally

**Files: ~200 lines of test infrastructure. Existing tests updated to use the new utility.**

---

## 6. Deeper Observations

### 6.1 What "Deep Module" Test Design Looks Like Here

The best tests in this codebase test at module boundaries:

- `LifecycleEventContractTest` tests the event-ordering contract that all runtime components must obey — it doesn't test internal implementations. **This is deep.**
- `SessionRunEventStoreTest` tests the JSONL persistence contract (append→read→survives recreation→validates embedded IDs→rejects corruption). It tests the boundary between in-memory domain events and filesystem persistence. **This is deep.**
- `ControllerE2eTestCase` tests the full controller-runtime boundary (JSONL protocol, process lifecycle, event sequence, session artifacts). It tests the boundary between the TUI head and the async runtime. **This is deep.**

The shallow tests:
- `SlashCommandRegistryTest` tests a thin wrapper around an associative array — 434 lines for what is essentially `array_key_exists()` + `array_merge()`. The interface is nearly as complex as the implementation. **This is shallow.**
- Tool definition tests (`testDefinitionNameIsEdit`, `testDefinitionHasDescription`, `testDefinitionJsonSchemaHasPathAndPatch` in `EditFileToolTest`) test static string properties of a DTO — these are better served as snapshot tests or schema validation.

### 6.2 Test Category Distribution (Unit vs Integration)

| Category | Count | Examples |
|----------|-------|----------|
| Pure unit (domain logic, no I/O) | ~65 | Handler tests with fake platforms/stores, Registry tests, Value Object tests |
| Integration (kernel-booted, test DB) | ~8 | LlamaCppSmokeTest, ModelSelectionServiceTest, HatfieldSessionStoreTest, DbalToolBatchStoreTest |
| E2E (process-spawning) | ~5 | ControllerSmokeTest, WriteFileToolE2eTest, ViewImageToolE2eTest, TuiStartupSnapshotTest, TuiAgentSmokeTest |
| Mixed boundary | ~15 | SessionRunEventStoreTest (filesystem I/O), EditFileToolTest (shells to diff), BackgroundProcessManagerTest |

The ratio is reasonable — heavy on unit tests, light on E2E. The gap is in the middle: domain-boundary and runtime-component tests.

### 6.3 Missing Test Annotations

Only ~15% of tests use `#[CoversClass]` or `@covers` annotations. Tests that do (e.g., `Runtime/Projection/*`, `Runtime/Stream/*`, `Tool/BgStatusToolTest`) tend to be better scoped. Adding `#[CoversClass]` to remaining tests would make the coverage report more actionable.

---

## Appendices

### A. Full Test File Inventory

```
tests/AgentCore/ (37 files)
  Application/Handler/ (12): AfterTurnCommitSerializerRegressionTest, CommandRouterContractTest,
    ExecutionFailureDrillTest, ExecutionWorkerTest, HookDispatcherContractTest, InMemoryToolBatchStoreTest,
    ReplayServiceTest, RunLockManagerTest, RunMetricsTest, RunTracerTest,
    ToolBatchCollectorDurableTest, ToolBatchCollectorTest, ToolExecutionPolicyResolverTest, ToolExecutorTest
  Application/Pipeline/ (6): AdvanceRunHandlerTest, ApplyCommandHandlerTest, CommandMailboxPolicyTest,
    LlmStepResultHandlerTest, StartRunHandlerTest, ToolCallResultHandlerTest
  Application/Tool/ (1): StackToolExecutionContextAccessorTest
  Contract/ (1): LifecycleEventContractTest
  Domain/Message/ (1): AgentMessageTest
  Infrastructure/Messenger/ (1): WorkerFailedEventSubscriberTest
  Infrastructure/Storage/ (1): InMemoryRunStoreCasTest
  Infrastructure/SymfonyAi/ (4): DynamicToolDescriptionProcessorTest, LlamaCppSmokeTest,
    PlatformIntegrationTest, TraceReplayTest
  Infrastructure/ (1): RunLogContextTest
  Schema/ (1): EventPayloadNormalizerTest
  Support/ (4): TestSerializerFactory, Fake/FakeToolExecutor, Fake/FakePlatform, SymfonyAiTestMessages

tests/CodingAgent/ (68 files)
  Config/ (8): AppConfigLoaderTest, CompatRequestShaperTest, HomeSettingsWriterTest,
    ModelSelectionServiceTest, ReasoningOptionsResolverTest, SessionAwareModelResolverTest,
    SettingsPathResolverTest + Ai/ (3): AiConfigTest, AiModelReferenceTest, HatfieldModelCatalogTest
  EventListener/ (1): RuntimeExceptionPolicySubscriberTest
  Extension/ (3): ExtensionManagerTest, ExtensionToolHookEventSubscriberTest, ExtensionToolRegistryBridgeTest
    Builtin/SafeGuard/ (7): SafeGuardConfigTest, SafeGuardExtensionTest, SafeGuardToolCallHookTest,
      Classifier/ (3): SafeGuardClassifierTest, SafeGuardCommandMatcherTest, SafeGuardPathMatcherTest,
      Policy/ (1): SafeGuardPolicyTest
  Infrastructure/SymfonyAi/ (2): ProjectedSymfonyModelCatalogTest, SymfonyAiProviderRegistryTest
  Logging/ (5): LogContextProcessorTest, LogContextTest, LogFilterTest, LogParserTest, LogReaderTest
  Path/ (1): PathResolverTest
  Runtime/ (12): JsonlCodecTest, RuntimeEventMapperTest, RuntimeEventTypeTest,
    Contract/ (1): RuntimeExceptionBoundaryTest,
    Controller/E2E/ (4): ControllerE2eTestCase, ControllerSmokeTest, ViewImageToolE2eTest, WriteFileToolE2eTest
    ErrorCapture/ (1): RuntimeErrorCaptureConfigTest,
    InProcess/ (1): InMemoryRuntimeEventSinkTest,
    Projection/ (2): TranscriptBlockTest, TranscriptProjectorTest,
    Stream/ (1): StreamDeltaSubscriberTest
  Session/ (4): AggregateResumeTest, HatfieldSessionStoreTest, SessionRunEventStoreTest, SessionRunStoreTest
  Skills/ (4): SkillContextRendererTest, SkillDiscoveryTest, SkillRegistryTest, SkillsContextBuilderTest
  SystemPrompt/ (3): AgentsContextDiscoveryTest, AgentsContextRendererTest, SystemPromptBuilderTest
  TestCase/ (1): IsolatedKernelTestCase
  Tool/ (14): BackgroundProcessManagerTest, BgStatusToolTest, CodingAgentToolSetResolverTest,
    EditFileToolTest, OutputCapTest, ReadFileToolTest, RegistryBackedToolboxTest,
    ToolRegistryTest, ToolRuntimeTest, ViewImageToolTest, WriteFileToolTest,
    ImageProcessing/ (1): ImageAttachmentProcessorTest,
    Store/ (1): DbalToolBatchStoreTest

tests/Tui/ (28 files)
  Command/ (4): CommandParserTest, SlashCommandRegistryTest, SubmissionRouterTest
    + handlers: FixedMessageTestHandler, EchoHandler, NoOpTestHandler
  E2E/ (3): TmuxHarness, TmuxPane, TuiAgentSmokeTest, TuiStartupSnapshotTest
  Editor/ (2): EditorStateTest, PromptEditorTest
  Extension/ (1): SlotBasedTuiExtensionContextTest
  Footer/ (1): FooterBarWidgetTest
  Layout/ (2): ChatLayoutTest, TuiSlotRegistryTest
  Listener/ (3): CancelListenerTest, FooterStateSegmentProviderTest, ModelCommandHandlerTest
  Picker/ (1): ModelPickerControllerTest
  Question/ (3): QuestionControllerTest, QuestionCoordinatorTest, QuestionRequestTest
  Theme/ (3): DefaultThemeTest, ThemePaletteTest, ThemeRegistryTest
  Transcript/ (1): TranscriptBlockRendererTest
  Widget/ (1): TuiRenderContextTest
  Snapshots/ (1): startup-120x40.txt
```

### B. Filesystem Isolation Pattern Comparison

| Pattern | CWD Behavior | Dir Location | Cleanup | Use Case |
|---------|-------------|-------------|---------|----------|
| IsolatedKernelTestCase | chdir() before kernel boot | var/tests/hatfield-test-<hex> | tearDown() restores CWD + removes dir | Kernel-booted tests needing .hatfield/ isolation |
| sys_get_temp_dir + manual | No chdir | /tmp/hatfield-*-<pid> | tearDown() removes dir | Filesystem tests without kernel boot |
| var/tmp/<prefix>-<uniqid> | No chdir | var/tmp/<prefix>-<uniqid> | tearDown() removes dir | E2E process-spawning tests |

### C. Test Groups and Opt-In Behavior

| Group | Test | Requires | Skipped When |
|-------|------|----------|--------------|
| `llm-real` | ControllerSmokeTest | `LLAMA_CPP_SMOKE_TEST=1` | Env not set |
| `llm-real` | WriteFileToolE2eTest | `LLAMA_CPP_SMOKE_TEST=1` | Env not set |
| `llm-real` | ViewImageToolE2eTest | `LLAMA_CPP_SMOKE_TEST=1` | Env not set |
| `llm-real` | LlamaCppSmokeTest | `LLAMA_CPP_SMOKE_TEST=1` | Env not set |
| `tui-e2e` + `llm-real` | TuiAgentSmokeTest | `LLAMA_CPP_SMOKE_TEST=1` + tmux | Env not set or tmux missing |
| `tui-e2e` | TuiStartupSnapshotTest | tmux | tmux missing |
| (none) | ViewImageToolTest | GD WebP support | Library missing |
| (none) | ImageAttachmentProcessorTest | GD or Imagick | Neither available |
| (none) | BackgroundProcessManagerTest | Linux + pdo_sqlite | OS/extension missing |
| (none) | BgStatusToolTest | Linux + pdo_sqlite | OS/extension missing |
