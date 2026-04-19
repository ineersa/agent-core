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

## Stage 04 — Orchestrator and Worker Topology

Status: **completed**

### Completed

- Implemented orchestrator-owned mutation flow with lock + CAS guardrails:
  - `src/Application/Orchestrator/RunOrchestrator.php`
  - now handles command messages and executor result messages:
    - `StartRun`
    - `ApplyCommand`
    - `AdvanceRun`
    - `LlmStepResult`
    - `ToolCallResult`
  - enforces stale result checks by `turn_no` + `step_id`
  - records `stale_result_ignored` audit events
- Added lock manager integration using Symfony Lock:
  - `composer.json` / `composer.lock` (added `symfony/lock`)
  - `src/Application/Handler/RunLockManager.php`
  - `config/services.php` (`LockFactory` + lock store wiring)
- Added idempotency + tool-batch orchestration services:
  - `src/Application/Handler/MessageIdempotencyService.php`
  - `src/Application/Handler/ToolBatchCollector.php`
  - `src/Application/Handler/ToolBatchCollectOutcome.php`
- Added execution worker handlers (pure executors):
  - `src/Application/Handler/ExecuteLlmStepWorker.php`
  - `src/Application/Handler/ExecuteToolCallWorker.php`
- Updated message contracts for worker topology:
  - `src/Domain/Message/ExecuteLlmStep.php` (`contextRef`, `toolsRef`)
  - `src/Domain/Message/ExecuteToolCall.php` (`args`, `toolIdempotencyKey`)
  - `src/Domain/Message/LlmStepResult.php`
  - `src/Domain/Message/ToolCallResult.php`
- Updated reducer/runtime state for active step ownership and effect emission:
  - `src/Application/Reducer/RunReducer.php`
  - `src/Domain/Run/RunState.php` (`activeStepId`)
- Added DB-CAS style API on run store abstraction:
  - `src/Contract/RunStoreInterface.php`
  - `src/Infrastructure/Storage/InMemoryRunStore.php`
- Updated messenger topology + service graph for new workers/messages:
  - `config/messenger.php`
  - `config/services.php`

### Tests Added/Updated

- Added:
  - `tests/Application/Handler/ExecutionWorkerTest.php`
  - `tests/Application/Orchestrator/RunOrchestratorTopologyTest.php`
  - `tests/Infrastructure/Storage/InMemoryRunStoreCasTest.php`
- Updated:
  - `tests/Config/MessengerConfigTest.php`
  - `tests/DependencyInjection/AgentLoopExtensionTest.php`
  - `tests/Kernel/TestKernel.php`
  - `tests/Integration/KernelIntegrationTest.php`

### Quality/Verification

- `LLM_MODE=true castor dev:check` ✅
  - `cs-fix`: ok
  - `phpstan`: ok
  - `test`: ok (`39 tests`, `206 assertions`)

### Stage 04 closure

- Stage 04 worker topology, stale-guard checks, lock coordination, and CAS-path protections are implemented and verified.

## Stage 05 — Symfony AI Integration

Status: **completed**

### Completed

- Implemented Symfony AI invocation pipeline with streaming reduction and normalized outcomes:
  - `src/Infrastructure/SymfonyAi/Platform.php`
  - `src/Infrastructure/SymfonyAi/SymfonyPlatformInvoker.php`
  - `src/Infrastructure/SymfonyAi/StreamDeltaReducer.php`
  - `src/Infrastructure/SymfonyAi/SymfonyMessageMapper.php`
  - `src/Infrastructure/SymfonyAi/RunCancellationToken.php`
  - `src/Domain/Tool/PlatformInvocationResult.php`
- Added optional model-selection seam for per-turn routing:
  - `src/Contract/Tool/ModelResolverInterface.php`
  - `src/Domain/Tool/ResolvedModel.php`
- Added dynamic tool-catalog seam with schema-stability guardrails:
  - `src/Contract/Tool/ToolCatalogProviderInterface.php`
  - `src/Application/Handler/ToolCatalogResolver.php`
  - `src/Domain/Tool/ToolDefinition.php`
- Added Symfony toolbox adapter for tool execution bridge:
  - `src/Infrastructure/SymfonyAi/SymfonyToolExecutorAdapter.php`
  - wired as `ToolExecutorInterface` default with fallback to existing policy executor
- Integrated hook semantics:
  - transform/convert/provider hooks in Symfony AI pipeline
  - extension hook namespace support (`ext:*`) in `HookDispatcher`
  - post-commit hook emission (`after_turn_commit`) from orchestrator commit path
- Improved cancellation path:
  - cancellation token observed during streaming
  - aborted result mapped to `stop_reason=aborted`
  - orchestrator now transitions cancelled runs deterministically on aborted LLM result
- Added LLM config defaults:
  - `agent_loop.llm.default_model` in bundle configuration + extension parameters

### Tests Added/Updated

- Added:
  - `tests/Infrastructure/SymfonyAi/PlatformIntegrationTest.php`
  - `tests/Infrastructure/SymfonyAi/SymfonyToolExecutorAdapterTest.php`
- Updated:
  - `tests/Application/Handler/HookDispatcherContractTest.php`
  - `tests/Application/Orchestrator/RunOrchestratorTopologyTest.php`
  - `tests/DependencyInjection/ConfigurationTest.php`
  - `tests/DependencyInjection/AgentLoopExtensionTest.php`

### Quality/Verification

- `LLM_MODE=true castor dev:check` ✅
  - `cs-fix`: ok
  - `phpstan`: ok
  - `test`: ok (`45 tests`, `236 assertions`)

### Stage 05 closure

- Stage 05 Symfony AI integration deliverables are implemented and passing quality gates.
- Next implementation target: `implementation/06-tool-execution-hitl-and-parallelism.md`.

## Stage 06 — Tool Execution, HITL, and Parallelism

Status: **completed**

### Completed

- Implemented production tool execution engine in `ToolExecutor`:
  - policy resolution by tool mode/timeout/parallelism
  - argument schema preflight validation
  - `beforeToolCall` block support and `afterToolCall` override semantics
  - interrupt-mode contract payload generation (`kind=interrupt`, `question_id`, `prompt`, `schema`)
  - cancellation-aware stale result handling for non-cooperative tools
- Added tool policy + result plumbing:
  - `src/Application/Handler/ToolExecutionPolicyResolver.php`
  - `src/Domain/Tool/ToolExecutionPolicy.php`
  - `src/Application/Handler/ToolExecutionResultStore.php`
  - `src/Contract/Tool/ToolIdempotencyKeyResolverInterface.php` (optional stronger idempotency seam)
- Added v1 idempotency guarantees:
  - baseline dedupe by `(run_id, tool_call_id)`
  - stronger reuse path by `(tool_name, tool_idempotency_key)` when key is present
- Upgraded tool execution message/contracts:
  - `ExecuteToolCall` now carries mode, timeout, maxParallelism, assistantMessage, and argSchema
  - `ToolCall` now carries runtime context (run metadata, mode/timeout, idempotency key, assistant message)
- Reworked orchestrator tool handling (`RunOrchestrator`):
  - mode selection per tool call
  - lifecycle emission for tool preflight + commit boundaries (`tool_execution_start`, `tool_execution_end`)
  - synthetic tool message lifecycle markers (`message_start`/`message_end` for tool role)
  - interrupt detection path transitions run to `waiting_human` and emits `waiting_human` event
  - late tool results ignored when run is `cancelling`/`cancelled`
- Reworked `ToolBatchCollector` for bounded dispatch:
  - sequential mode executes strictly one-at-a-time in assistant order
  - parallel mode dispatches up to max parallelism and backfills pending calls as results arrive
  - final ordered commit remains deterministic by `order_index`

### Tests Added/Updated

- Added:
  - `tests/Application/Handler/ToolExecutorTest.php`
  - `tests/Application/Handler/ToolBatchCollectorTest.php`
- Updated:
  - `tests/Application/Orchestrator/RunOrchestratorTopologyTest.php`
  - `tests/Infrastructure/SymfonyAi/SymfonyToolExecutorAdapterTest.php`

### Quality/Verification

- `LLM_MODE=true castor dev:check` ✅
  - `cs-fix`: ok
  - `phpstan`: ok
  - `test`: ok (`53 tests`, `276 assertions`)

### Stage 06 closure

- Stage 06 tool execution, interrupt handling, idempotency, and bounded parallel collection are implemented and passing quality gates.

## Stage 07 — Steering, Cancel, Continue, and Resume

Status: **completed**

### Completed

- Added canonical core command constants in `CoreCommandKind` for:
  - `steer`
  - `follow_up`
  - `cancel`
  - `human_response`
  - `continue`
- Reworked command routing policy in `CommandRouter`:
  - reserves core command kinds from extension handlers
  - enforces strict options schema
  - validates/normalizes `cancel_safe` for extension commands
- Implemented command mailbox semantics in `RunOrchestrator`:
  - `ApplyCommand` now queues commands instead of mutating state inline
  - idempotent enqueue and duplicate protection
  - queue-cap rejection for non-cancel commands
  - cancel-priority handling and deterministic rejection paths
  - "latest steer wins" supersede behavior
  - command draining at turn-start and stop boundaries
  - continue-vs-cancel conflict handling (`continue` rejected while cancellation is in progress)
- Extended retry/continue modeling in `RunState`:
  - added retryable failure metadata (`retryableFailure`)
  - continue flow now validates retryability and last-message role constraints
- Added stale run recovery path:
  - `RunStoreInterface::findRunningStaleBefore(...)`
  - timestamp-based stale detection in `InMemoryRunStore`
  - new console command: `AgentLoopResumeStaleRunsCommand` (`agent-loop:resume-stale-runs`)
  - stale resume path rebuilds hot prompt state via `ReplayService` when missing, then dispatches `AdvanceRun`
- Expanded command store contract and in-memory implementation:
  - pending mailbox reads (`pending`, `countPending`, `has`)
  - lifecycle markers (`markApplied`, `markRejected`, `markSuperseded`)
  - kind-based bulk rejection (`rejectPendingByKind`)
- Added DI/config knobs for stage behavior:
  - `commands.steer_drain_mode`
  - `commands.resume_stale_after_seconds`

### Tests Added/Updated

- Added:
  - `tests/Application/Orchestrator/CommandMailboxPolicyTest.php`
  - `tests/Command/AgentLoopResumeStaleRunsCommandTest.php`
- Updated:
  - `tests/Application/Handler/CommandRouterContractTest.php`
  - `tests/Application/Orchestrator/RunOrchestratorTopologyTest.php`
  - `tests/DependencyInjection/ConfigurationTest.php`
  - `tests/DependencyInjection/AgentLoopExtensionTest.php`
  - `tests/Infrastructure/Storage/InMemoryRunStoreCasTest.php`
  - `tests/Integration/KernelIntegrationTest.php`

### Quality/Verification

- `LLM_MODE=true castor dev:check` ✅
  - `cs-fix`: ok
  - `phpstan`: ok
  - `test`: ok (`60 tests`, `307 assertions`)

### Stage 07 closure

- Stage 07 control-plane semantics (steer/follow-up/cancel/human-response/continue) and stale-run resume recovery are implemented and passing quality gates.

## Stage 08 — API Surface and Mercure Streaming

Status: **completed**

### Completed

- Implemented HTTP API controller surface in `src/Api/Http/RunApiController.php`:
  - `POST /agent/runs`
  - `POST /agent/runs/{runId}/commands`
  - `GET /agent/runs/{runId}`
  - `GET /agent/runs/{runId}/messages`
  - `GET /agent/runs/{runId}/events` (reconnect replay endpoint)
- Added API read model service:
  - `src/Api/Http/RunReadService.php`
  - summary projection, transcript pagination, canonical-event replay + JSONL fallback
- Added run access scoping for per-run authorization:
  - `src/Contract/RunAccessStoreInterface.php`
  - `src/Domain/Run/RunAccessScope.php`
  - `src/Infrastructure/Storage/InMemoryRunAccessStore.php`
  - endpoints now enforce tenant/user scoping via `X-Agent-Tenant-Id` + `X-Agent-User-Id`
- Added stream event DTO and serializer:
  - `src/Api/Dto/RunStreamEvent.php`
  - `src/Api/Serializer/RunEventSerializer.php`
- Upgraded Mercure publishing policy:
  - `src/Infrastructure/Mercure/RunTopicPolicy.php`
  - `src/Infrastructure/Mercure/RunEventPublisher.php`
  - topic pattern aligned to `agent/runs/{runId}`
  - Mercure update id/type now set from `seq`/event `type`
  - payload shape now emits `ts` field
  - `message_update` coalescing window added; terminal events remain published
- Wired API and streaming services in DI:
  - `config/services.php`
- Enabled route import in test kernel:
  - `tests/Kernel/TestKernel.php`
- Updated architecture READMEs for new API and event-stream flow:
  - `src/Api/Http/README.md`
  - `src/Api/Dto/README.md`
  - `src/Application/README.md`
  - `src/Domain/Event/README.md`

### Tests Added/Updated

- Added:
  - `tests/Api/Http/RunApiControllerTest.php`
  - `tests/Infrastructure/Mercure/RunEventPublisherTest.php`
- Updated:
  - `phpstan-baseline.neon` (removed now-stale ignores after API usage made symbols reachable)

### Quality/Verification

- `LLM_MODE=true castor dev:check` ✅
  - `cs-fix`: ok
  - `phpstan`: ok
  - `test`: ok (`66 tests`, `411 assertions`)
- `LLM_MODE=true castor dev:index-methods` ✅ (indexes regenerated for changed/new classes)

### Stage 08 closure

- Stage 08 API + streaming surface is implemented with scoped authorization, replay-aware reconnect behavior, Mercure topic policy, and serializer-backed event envelopes.

## Stage 09 — Testing, Observability, and Debugging

Status: **completed** (split into parts; parts 1-3 completed)

### Completed in part 1

- Added debug read-model service:
  - `src/Application/Handler/RunDebugService.php`
  - supports consolidated inspect snapshots, replay windows, tail windows, and hot-state rebuild entrypoint.
- Added debug CLI tooling:
  - `agent-loop:run-inspect`
  - `agent-loop:run-replay`
  - `agent-loop:run-rebuild-hot-state`
  - `agent-loop:run-tail`
  - implemented in:
    - `src/Command/AgentLoopRunInspectCommand.php`
    - `src/Command/AgentLoopRunReplayCommand.php`
    - `src/Command/AgentLoopRunRebuildHotStateCommand.php`
    - `src/Command/AgentLoopRunTailCommand.php`
- Added structured event logging in orchestrator commit path:
  - `src/Application/Orchestrator/RunOrchestrator.php`
  - each committed event now logs required fields:
    - `run_id`, `turn_no`, `step_id`, `seq`, `status`, `worker_id`, `attempt`
- Expanded unit/contract coverage for stage goals:
  - `tests/Application/Reducer/RunReducerTransitionTest.php` (reducer transition table + command application)
  - `tests/Application/Orchestrator/RunOrchestratorStructuredLoggingTest.php` (structured log context contract)
  - `tests/Command/AgentLoopRunDebugCommandsTest.php` (new debug command behaviors)
- Updated DI/kernel verification for new services/commands:
  - `tests/DependencyInjection/AgentLoopExtensionTest.php`
  - `tests/Integration/KernelIntegrationTest.php`

### Quality/Verification (part 1)

- `LLM_MODE=true castor dev:check` ✅
  - `cs-fix`: ok
  - `phpstan`: ok
  - `test`: ok (`75 tests`, `487 assertions`)
- `LLM_MODE=true castor dev:index-methods` ✅

### Completed in part 2

- Added in-process metrics primitives and histogram support:
  - `src/Application/Handler/LatencyHistogram.php`
  - `src/Application/Handler/RunMetrics.php`
- Added lightweight trace span instrumentation:
  - `src/Application/Handler/RunTracer.php`
  - root spans for command/turn processing in orchestrator and execution workers
  - child spans for `llm.call`, `tool.call`, command-boundary application, and `persistence.commit`
- Wired observability collection through runtime services:
  - `RunOrchestrator` now records:
    - active-runs-by-status transitions
    - command queue lag
    - stale-result count
    - turn completion durations
  - `ExecuteLlmStepWorker` now records LLM latency + error rate
  - `ExecuteToolCallWorker` now records tool latency + timeout/error rates
  - `ReplayService` now records replay rebuild counters and spans
- Exposed metrics through debug tooling:
  - `RunDebugService::inspect()` now includes `metrics`
  - `agent-loop:run-inspect` renders an “Observability metrics” section when available
- Updated architecture notes for observability wiring:
  - `src/Application/README.md`

### Tests added/updated in part 2

- Added:
  - `tests/Application/Handler/RunMetricsTest.php`
  - `tests/Application/Orchestrator/RunOrchestratorObservabilityTest.php`
- Updated:
  - `tests/Application/Handler/ExecutionWorkerTest.php` (worker metrics + tracing assertions)
  - `tests/DependencyInjection/AgentLoopExtensionTest.php`
  - `tests/Integration/KernelIntegrationTest.php`
  - `tests/Kernel/TestKernel.php`

### Quality/Verification (part 2)

- `LLM_MODE=true castor dev:check` ✅
  - `cs-fix`: ok
  - `phpstan`: ok
  - `test`: ok (`79 tests`, `519 assertions`)
  - `summaries`: ok
- `LLM_MODE=true castor dev:index-methods` ✅

### Completed in part 3

- Added soak/load/failure-drill automation coverage:
  - `tests/Application/Orchestrator/RunOrchestratorSoakFailureDrillTest.php`
    - 1000 synthetic run soak scenario
    - duplicate-delivery stress on tool results with idempotent commit validation
    - transient event-store commit failure drill with rollback + retry validation
  - `tests/Application/Handler/ExecutionFailureDrillTest.php`
    - worker dispatch crash drills (LLM + tool worker) with successful retry path
  - `tests/Application/Handler/RunLockManagerTest.php`
    - lock manager execution + bounded acquisition timeout behavior
  - `tests/Application/Handler/OutboxProjectionWorkerTest.php`
    - JSONL append failure retry scheduling path
- Added fake provider/tool fixtures library for deterministic integration-style tests:
  - `tests/Support/Fake/FakePlatform.php`
  - `tests/Support/Fake/FakeToolExecutor.php`
- Hardened orchestrator commit path for failure drills:
  - `src/Application/Orchestrator/RunOrchestrator.php`
  - event-store persistence failures now trigger rollback attempt and structured warning logs
  - outbox projection, hot-state rebuild, effect dispatch, and after-commit hook failures are isolated with structured warnings
- Hardened lock acquisition behavior to avoid indefinite deadlocks during contention:
  - `src/Application/Handler/RunLockManager.php`
  - bounded non-blocking acquisition loop with configurable timeout
- Added ops artifacts for production operability:
  - `docs/operations/agent-loop-observability-dashboard.md`
  - `docs/operations/agent-loop-alert-rules.yaml`
  - `docs/operations/agent-loop-oncall-runbook.md`
  - referenced from root `README.md`
- Updated architecture notes for commit-failure observability:
  - `src/Application/README.md`

### Quality/Verification (part 3)

- `LLM_MODE=true castor dev:check` ✅
  - `cs-fix`: ok
  - `phpstan`: ok
  - `test`: ok (`87 tests`, `7553 assertions`)
  - `summaries`: ok
- `LLM_MODE=true castor dev:index-methods` ✅

### Stage 09 closure

- Stage 09 acceptance goals are implemented, including soak/load + failure drills, fake test doubles library, observability/ops artifacts, and debug recovery runbook support.
