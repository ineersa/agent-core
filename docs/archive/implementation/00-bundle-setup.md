# Stage 00 - Bundle Setup

## Goal
Create a Symfony-native bundle that provides a production-ready agent loop runtime with JS parity features from `packages/agent` and first-class integration with Symfony AI.

## Scope
- Create a new Symfony bundle package.
- Register core services and configuration.
- Define extension points for hooks, events, storage, and transport.
- Define extension seams for custom command kinds and custom event types.
- Set up Messenger buses and base message contracts.

## Out of Scope
- Full loop logic.
- Tool execution policies.
- Persistence schema migrations.
- UI APIs.

## Package Layout
Use this layout from day one to avoid later package churn.

```text
src/
  AgentLoopBundle.php
  DependencyInjection/
    AgentLoopExtension.php
    Configuration.php
  Contract/
    AgentRunnerInterface.php
    RunStoreInterface.php
    EventStoreInterface.php
    CommandStoreInterface.php
    PromptStateStoreInterface.php
    ArtifactStoreInterface.php
    Hook/
    Extension/
      CommandHandlerInterface.php
      HookSubscriberInterface.php
      EventSubscriberInterface.php
    Tool/
  Domain/
    Run/
    Message/
    Tool/
    Event/
    Command/
  Application/
    Orchestrator/
    Handler/
    Reducer/
  Infrastructure/
    Doctrine/
    Messenger/
    SymfonyAi/
    Storage/
    Mercure/
  Api/
    Http/
    Dto/
config/
  services.php
  messenger.php
  doctrine.php
```

## Initial Configuration Contract
Add one user-facing root config: `agent_loop`.

```yaml
agent_loop:
  runtime: messenger          # messenger|inline
  streaming: mercure          # mercure|sse
  storage:
    run_log:
      flysystem_storage: 'agent_loop.run_logs'
      base_path: '%kernel.project_dir%/var/agent-runs' # only used by local Flysystem adapter
    hot_prompt:
      backend: doctrine
  tools:
    defaults:
      mode: sequential         # sequential|parallel|interrupt
      timeout_seconds: 90
    max_parallelism: 4
    overrides:
      web_search:
        mode: parallel
        timeout_seconds: 120
      ask_user:
        mode: interrupt
  commands:
    max_pending_per_run: 100
    custom_kind_prefix: 'ext:'
  events:
    custom_type_prefix: 'ext_'
  checkpoints:
    every_turns: 5
    max_delta_kb: 256
  retention:
    hot_prompt_ttl_hours: 24
    archive_after_days: 7
```

## Core Services to Wire
- `AgentRunner` facade service.
- `RunOrchestrator` single-writer coordinator.
- `RunReducer` pure state transition function.
- `StepDispatcher` Messenger-based effect dispatcher.
- `PlatformInterface` invoker service (Symfony AI native, no provider abstraction layer).
- `ToolExecutor` with policy and mode selection.
- `RunLogWriter` append-only JSONL writer.
- `RunEventStore` canonical event persistence.
- `OutboxProjector` for JSONL/Mercure projection.
- `CommandRouter` (built-in handlers + extension command handlers).
- `HookDispatcher` (core hooks + extension boundary hooks).
- `RunEventDispatcher` (core lifecycle events + extension namespaced events).
- `RunEventPublisher` using Mercure `HubInterface` for external stream publishing.
- Symfony `EventDispatcherInterface` for internal hook/event dispatch.

## Messenger Buses and Routing
- `agent.command.bus`: orchestrator commands (`StartRun`, `ApplyCommand`, `AdvanceRun`).
- `agent.execution.bus`: execution tasks (`ExecuteLlmStep`, `ExecuteToolCall`, `CollectToolBatch`).
- `agent.publisher.bus` (optional): publish-only side effects to avoid backpressure in command path.

## Required Coding Rules
- Single writer per `run_id`: only orchestrator mutates run state.
- Executor workers never write run state directly.
- Every message carries `run_id`, `turn_no`, `step_id`, `attempt`, `idempotency_key`.
- Core command kinds are reserved (`steer|follow_up|cancel|human_response|continue`); extension kinds must use `ext:` prefix.
- Core lifecycle event types are reserved; extension event types must use `ext_` prefix.
- Unknown extension command kinds are rejected deterministically and persisted as explicit rejected-command events.
- `CommandHandlerInterface` must expose cancel-safe capability per extension command kind; command `options.cancel_safe=true` is ignored/rejected unless capability allows it.

## Deliverables
- Bundle skeleton and DI extension.
- Config schema + defaults.
- Empty contracts and base domain value objects.
- Messenger buses and routing config.
- Flysystem wiring for run logs (local adapter default).
- Extension contracts and registry wiring for command/hook/event handlers.
- Basic health command (`agent-loop:health`).

## Acceptance Criteria
- Bundle installs and boots in Symfony app.
- `bin/console debug:container` shows all core services.
- `agent_loop` config validates and compiles.
- `bin/console debug:messenger` shows explicit routing for command and execution message classes.
- Dispatching `StartRun` and `ExecuteLlmStep` in a test kernel routes to different configured transports.

## Risks and Mitigations
- Risk: early coupling to one storage backend.
  - Mitigation: contract-first stores from day one.
- Risk: config explosion.
  - Mitigation: strict defaults, add options only with a concrete stage need.
