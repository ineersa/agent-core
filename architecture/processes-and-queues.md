# Processes and queues

[Architecture map](README.md) · [Request lifecycle](request-lifecycle.md)

## Startup and ownership

`ExtensionLoaderSubscriber` runs on `ConsoleEvents::COMMAND`, before the command
body. It also runs in consumer console processes. It does not wait for migrations
inside `AgentCommand`.

```mermaid
%%{init: {"sequence": {"wrap": true, "width": 60, "actorMargin": 10, "diagramMarginX": 10, "messageMargin": 30}}}%%
sequenceDiagram
    actor User
    participant Console as Console application
    participant Ext as ExtensionLoaderSubscriber
    participant Command as AgentCommand
    participant Migrate as StartupDatabaseMigrator
    participant TUI as InteractiveMode
    participant Client as Process session client
    participant Controller as HeadlessController
    participant Lock as Session owner lock
    participant Lifecycle as Session lifecycle cleanup
    participant Supervisor as ConsumerSupervisor
    participant Consumers as Messenger consumers

    User->>Console: hatfield agent
    Console->>Ext: ConsoleEvents::COMMAND
    Ext->>Ext: Load enabled extensions
    Console->>Command: Execute command body
    Command->>Command: Apply CWD, configuration, CLI filters
    Command->>Migrate: Application and transport migrations
    Migrate-->>Command: Startup databases ready
    Command->>TUI: Interactive mode with runtime client
    TUI->>Client: Start or resume session
    Client->>Controller: Spawn agent --controller
    Note over Console,Controller: Controller is another console process with its own extension loading
    Controller->>Lock: Acquire owner lock
    alt Another controller owns session
        Lock-->>Controller: Acquisition fails
        Controller-->>Client: Exit before runtime.ready
    else Ownership acquired
        Controller->>Controller: Reap matching orphan consumers
        Controller->>Lifecycle: Startup cleanup, parent and child scopes
        Controller->>Lifecycle: Clear disposable operational projection rows
        Controller->>Supervisor: Launch configured consumer pools
        Supervisor->>Consumers: messenger:consume with session transport DSNs
        Note over Consumers: Each consumer boots the console and extension registrations
        Supervisor-->>Controller: Pools launched
        Controller-->>Client: runtime.ready
        Client-->>TUI: Commands can be sent
    end
```

Launching pools is not proof that a provider request or extension job will succeed.
The controller owns the lock and process lifecycle; run control owns transitions.

## Bus routing

```mermaid
flowchart TB
    Handlers[Controller command handlers] --> CB[agent.command.bus]
    Results[Worker result messages] --> CB
    Step[StepDispatcher] -->|RunControlTransitionMessageInterface| CB
    Step -->|Execution effects| EB[agent.execution.bus]
    CB --> RC[(run_control)]
    EB --> Sub{ExecuteToolCall is subagent or agent_resume?}
    Sub -->|Yes: stamp agent| Agent[(agent)]
    Sub -->|No| MCP{MCP-backed and no existing transport stamp?}
    MCP -->|Yes: stamp mcp| MQ[(mcp)]
    MCP -->|No| YAML{Default YAML route}
    YAML -->|ExecuteToolCall, including fork| Tool[(tool)]
    YAML -->|ExecuteShellToolCall| Tool
    YAML -->|ExecuteLlmStep / ExecuteCompactionStep| LLM[(llm)]
    Jobs[ExtensionAgentJobDispatcher] -->|ExtensionAgentJobMessage| EQ[(extension_agent)]
    Life[MCP lifecycle messages] --> MQ
```

The subagent middleware runs before MCP routing. MCP middleware preserves an
existing `TransportNamesStamp`. Extension job dispatch is separate from
`StepDispatcher`'s transition-versus-execution choice.

## Exact YAML route inventory

All 22 explicitly routed message classes from `config/packages/messenger.yaml`
are listed here by short class name. Dynamic `ExecuteToolCall` overrides are above.

| Transport | Messages |
|---|---|
| `run_control` | `StartRun`, `ApplyCommand`, `ApplyShellCommand`, `InvalidateRunContext` |
| `run_control` | `LlmStepResult`, `ToolCallResult`, `CompactionStepResult` |
| `run_control` | `AdvanceRun`, `CompactRun`, `CompleteDeferredToolCall` |
| `run_control` | `ObserveDeferredSubagentBatchChildTurnMessage`, `DeliverDeferredSubagentBatchLifecycleMessage` |
| `run_control` | `InterruptDeferredSubagentBatchMessage`, `RecoverDeferredSubagentBatchLifecycleMessage` |
| `llm` | `ExecuteLlmStep`, `ExecuteCompactionStep` |
| `tool` | `ExecuteToolCall`, `ExecuteShellToolCall` |
| `mcp` | `McpInitializeSessionCommand`, `McpRefreshCatalogCommand`, `McpDisconnectSessionCommand` |
| `extension_agent` | `ExtensionAgentJobMessage` |

| Consumer | Count | Responsibility |
|---|---|---|
| `run_control` | 1 | Commands, results, state transitions, canonical commits |
| `llm` | 1–8, default 4 | Model and compaction execution |
| `tool` | `tools.execution.max_parallelism` | Generic tools, shell, fork |
| `agent` | 1 | Subagent and resume tool execution |
| `mcp` | 1 | MCP tools and connection lifecycle |
| `extension_agent` | 1 | Extension-owned agent jobs |
| `scheduler_default` | 1 | Generated recurring schedule messages |

## Scheduled work is not delayed run control

```mermaid
flowchart TB
    Clock["Symfony Scheduler<br/>Live in-memory default schedule"]
    Clock -->|Every 30 seconds| Index[completion:file-index:refresh]
    Clock -->|Every 300 seconds| Cleanup[BackgroundProcessProvisionalCleanupTask]
    Cleanup --> Private[Finished private foreground rows and sidecars]
```

```mermaid
flowchart TB
    Batch[Deferred subagent batch deadline] --> Delay[DelayStamp]
    Delay --> Queue[(Durable run_control transport)]
    Queue --> Interrupt[InterruptDeferredSubagentBatchMessage]
    Interrupt --> Children[Interrupt unfinished children and settle parent wait]
```

The scheduler is not the durable deadline store. Accepted background processes are
not the provisional cleanup task's target.

## Shutdown and crashes

```mermaid
%%{init: {"sequence": {"wrap": true, "width": 110, "actorMargin": 20, "diagramMarginX": 10, "messageMargin": 30}}}%%
sequenceDiagram
    participant TUI
    participant Client as Process client
    participant Controller
    participant Supervisor
    participant Workers as Owned consumers
    participant BG as Background process records
    participant Next as Next controller startup

    TUI->>Client: Close session client
    Client->>Controller: Close transport / terminate owned controller
    Controller->>Supervisor: Shutdown on EOF, fatal stdout failure, or stop
    Supervisor->>Workers: Graceful stop within owned lifecycle
    Controller->>BG: Clean accepted background processes for owned scopes
    Note over Controller,Workers: MCP worker stop attempts graceful disconnect
    alt Abrupt process loss
        Next->>Next: Acquire same session owner lock first
        Next->>Workers: Reap matching orphan consumers
        Next->>BG: Startup cleanup for recorded ownership
    end
```

Consumer restarts are bounded to 3 per key within 60 seconds. A crash can leave a
claimed transport row unavailable. Restart is not automatic repair of the current
effect. [State and recovery](state-and-recovery.md) explains explicit repair.
Never signal root-owned workers or active processes tagged with `HATFIELD_SESSION_ID`.

Sources: [messenger.yaml](../config/packages/messenger.yaml),
[HeadlessController](../src/CodingAgent/Runtime/Controller/HeadlessController.php),
[StepDispatcher](../src/AgentCore/Application/Handler/StepDispatcher.php),
[runtime reference](../docs/async-runtime-architecture.md).
