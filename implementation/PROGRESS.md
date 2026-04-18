# Implementation Progress

## Stage 00 — Bundle Setup

Status: **completed**

### Completed

- Created Symfony bundle bootstrap:
  - `src/AgentLoopBundle.php`
  - `src/DependencyInjection/AgentLoopExtension.php`
  - `src/DependencyInjection/Configuration.php`
- Added root `agent_loop` config tree with defaults from stage spec:
  - runtime, streaming, storage, tools, commands, events, checkpoints, retention
- Added package layout scaffolding across namespaces:
  - `Contract/`, `Domain/`, `Application/`, `Infrastructure/`, `Api/`
- Added core contracts and DTO/value-object skeletons for:
  - runner/store contracts
  - hook parity contracts
  - extension seams (`CommandHandlerInterface`, hook/event subscribers)
  - base command/event/message/run/tool models
- Added messenger topology/config:
  - `config/messenger.php`
  - buses: `agent.command.bus`, `agent.execution.bus`, `agent.publisher.bus`
  - routing for `StartRun`, `ApplyCommand`, `AdvanceRun`, `ExecuteLlmStep`, `ExecuteToolCall`, `CollectToolBatch`
- Added core service wiring:
  - `config/services.php`
  - `AgentRunner`, `RunOrchestrator`, `RunReducer`, `StepDispatcher`, `ToolExecutor`, `CommandRouter`, hook/event dispatchers
- Added default flysystem run-log wiring:
  - local adapter + filesystem service
  - `Infrastructure/Storage/RunLogWriter.php`
- Added Mercure publisher scaffold:
  - `Infrastructure/Mercure/RunEventPublisher.php`
- Added extension registries:
  - command/hook/event subscriber registries
- Added health command:
  - `src/Command/AgentLoopHealthCommand.php` (`agent-loop:health`)

### Symfony Version

- Runtime upgraded to **Symfony 8** dependencies in `composer.json` (as requested).

### Reference bundle used for testing/config patterns

- Reference: `/home/ineersa/projects/mate/ai/src/ai-bundle`
- Used as guidance for:
  - dependency-injection focused bundle tests
  - container-driven configuration verification
  - command/runtime test style in bundle context

### Quality/Verification

- `LLM_MODE=true castor dev:cs-fix` ✅
- `LLM_MODE=true castor dev:test` ✅ (`8 tests`, `83 assertions`)
  - DI/config tests:
    - `tests/DependencyInjection/ConfigurationTest.php`
    - `tests/DependencyInjection/AgentLoopExtensionTest.php`
    - `tests/Config/MessengerConfigTest.php`
  - Full-kernel integration tests:
    - `tests/Kernel/TestKernel.php`
    - `tests/Integration/KernelIntegrationTest.php`
      - bundle boots in a real Symfony kernel
      - core services are reachable via test-only aliases (services stay private in runtime)
      - dispatching `StartRun` vs `ExecuteLlmStep` reaches distinct configured transports
- `LLM_MODE=true castor dev:phpstan` ⏭️ skipped per request

### Placeholder tightening completed for stage handoff

- `RunReducer` now applies minimal deterministic transitions for `StartRun`, `AdvanceRun`, and `ApplyCommand` instead of a pure no-op placeholder.
- Rejected command path in `RunOrchestrator` now advances run sequence/version and persists a deterministic rejection event envelope.
- `Infrastructure\SymfonyAi\Platform::invoke()` now fails fast with an explicit stage handoff message (stage 05 ownership) instead of returning a silent dummy payload.
- `ToolExecutor` placeholder behavior is documented with explicit stage ownership notes (stage 06).

### Stage 00 closure

- Stage 00 acceptance checks are covered with bundle/config/integration tests and pass in the current branch.

## Stage 01 — JS Parity Contracts (Hooks and Events)

Status: **completed**

### Completed

- Upgraded hook contracts for JS parity semantics:
  - `ConvertToLlmHookInterface` now returns `MessageBag`
  - `TransformContextHookInterface`, `BeforeToolCallHookInterface`, `AfterToolCallHookInterface`, and `BeforeProviderRequestHookInterface` now accept an optional cancellation token
  - Added `CancellationTokenInterface` + `NullCancellationToken`
- Added explicit DTO/value objects for hook payload/results:
  - `Domain/Message/MessageBag.php`
  - `Domain/Tool/BeforeToolCallResult.php`
  - `Domain/Tool/AfterToolCallResult.php`
  - `Domain/Tool/ProviderRequest.php`
- Added lifecycle event class hierarchy and stable type map:
  - `Domain/Event/Lifecycle/*Event.php`
  - `CoreLifecycleEventType` now exposes constants, class map, and ordering validation (`validateOrder`)
- Added boundary hook constants:
  - `Domain/Event/BoundaryHookName.php`
  - `HookDispatcher` now enforces known boundary hook names
- Added extension event guardrails:
  - `RunEvent::extension(...)` enforces `ext_` prefix for custom event types
- Tightened extension command option contract:
  - `CommandRouter` now validates strict `options` schema (`cancel_safe` only, boolean)
  - non-conforming `ext:*` command options are deterministically rejected
- Upgraded message/state parity model:
  - `AgentMessage` now includes parity fields (`name`, `tool_call_id`, `tool_name`, `details`, `is_error`, `metadata`) and `toArray()`
  - `RunReducer` now hydrates incoming serialized messages into `AgentMessage` instances

### Contract Tests Added

- `tests/Contract/LifecycleEventContractTest.php`
  - covers prompt, continue, tools, steering, follow-up, cancel event ordering
  - validates extension event barrier around assistant `message_end` → tool preflight
- `tests/Contract/HookParityContractTest.php`
  - validates documented hook call order:
    - `transformContext` → `convertToLlm` → `beforeProviderRequest`
    - `beforeToolCall` → `afterToolCall`
- `tests/Application/Handler/CommandRouterContractTest.php`
  - covers `ext:*` routing, strict options schema, and deterministic rejection paths
- `tests/Application/Handler/RunEventDispatcherContractTest.php`
  - verifies core + extension event dispatch integration
- `tests/Application/Handler/HookDispatcherContractTest.php`
  - verifies boundary hook context mutation and unknown-hook rejection

### Quality/Verification

- `LLM_MODE=true castor dev:check` ✅
  - `cs-fix`: ok
  - `phpstan`: ok (`phpstan-baseline.neon` enabled)
  - `test`: ok (`27 tests`, `114 assertions`)

### Stage 01 closure

- Stage 01 acceptance checks are implemented and passing in the current branch.

## Stage 03 — Persistence (Hot/Cold Storage)

Status: **completed**

### Completed

- Added stage-03 persistence schema migration:
  - `src/Infrastructure/Doctrine/Migrations/Version20260418000100.php`
  - Covers: `agent_runs`, `agent_commands`, `agent_hot_prompt_state`, `agent_turn_index`, `agent_run_events`, `agent_outbox`, `agent_tool_jobs`
- Upgraded Doctrine prepend config:
  - `config/doctrine.php`
  - includes `doctrine_migrations` path for bundle migrations
- Added outbox persistence primitives:
  - `src/Contract/OutboxStoreInterface.php`
  - `src/Domain/Event/OutboxSink.php`
  - `src/Domain/Event/OutboxEntry.php`
  - `src/Infrastructure/Storage/InMemoryOutboxStore.php`
- Switched projection flow to queue-backed outbox projection:
  - `src/Application/Handler/OutboxProjector.php`
  - `src/Application/Handler/JsonlOutboxProjectorWorker.php`
  - `src/Application/Handler/MercureOutboxProjectorWorker.php`
  - `src/Domain/Message/ProjectJsonlOutbox.php`
  - `src/Domain/Message/ProjectMercureOutbox.php`
- Added JSONL replay/read support:
  - `src/Infrastructure/Storage/RunLogReader.php`
  - `src/Infrastructure/Storage/RunLogWriter.php` retained as append-only writer
- Added hot prompt state store implementation:
  - `src/Infrastructure/Storage/HotPromptStateStore.php`
  - wired as default `PromptStateStoreInterface` implementation
- Added replay/rebuild service with integrity checks:
  - `src/Application/Handler/ReplayService.php`
  - canonical event replay + JSONL fallback + missing-sequence detection
- Connected persistence updates into orchestrator rejection commit path:
  - `src/Application/Orchestrator/RunOrchestrator.php`
  - rejection event append -> outbox project -> hot prompt rebuild
- Updated ID generation toward stage storage constraints:
  - `src/Domain/Run/RunId.php` now generates UUID-v4 formatted public IDs

### Tests Added/Updated

- Added:
  - `tests/Application/Handler/OutboxProjectionWorkerTest.php`
  - `tests/Application/Handler/ReplayServiceTest.php`
  - `tests/Infrastructure/Storage/RunLogStorageTest.php`
  - `tests/Config/DoctrineConfigTest.php`
  - `tests/Domain/Run/RunIdTest.php`
- Updated:
  - `tests/Config/MessengerConfigTest.php`
  - `tests/DependencyInjection/AgentLoopExtensionTest.php`
  - `tests/Kernel/TestKernel.php`
  - `tests/Integration/KernelIntegrationTest.php`

### Quality/Verification

- `LLM_MODE=true castor dev:check` ✅
  - `cs-fix`: ok
  - `phpstan`: ok
  - `test`: ok (`34 tests`, `163 assertions`)

### Stage 03 closure

- Stage 03 persistence foundations are implemented and passing quality gates.
- Next implementation target: `implementation/04-orchestrator-and-workers.md`.
