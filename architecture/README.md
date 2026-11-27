# Hatfield architecture

Hatfield runs a terminal client, a session controller, a session queue broker,
and execution consumers. Processes provide isolation; Amp Fibers overlap waiting
handlers **inside** the LLM and tool consumer processes.

## Read by question

| Question | Reference |
|---|---|
| Which processes own execution? | [Processes and queues](processes-and-queues.md), [runtime supervision](runtime.md) |
| How does a prompt become an answer? | [Request lifecycle](request-lifecycle.md) |
| What is durable, and what can recover? | [State and recovery](state-and-recovery.md), [session runtime storage](session-runtime.md) |
| How do PHP scripts and recurring work run? | [Code mode and scheduling](code-mode-and-scheduling.md) |
| How are tools, approvals and MCP routed? | [Tools and MCP](tools-and-mcp.md) |
| How do extensions and child agents run? | [Extensions and agents](extensions-and-agents.md), [agent communication](agent-communication.md) |
| Who owns background work? | [Backgrounding](backgrounding.md) |
| What reaches the model and screen? | [Context and projection](context-and-projection.md), [history conversion](history-conversion.md), [TUI](tui.md) |
| Where are fresh database reads required? | [ORM freshness](orm-freshness-map.md) |
| How are releases and diagnostics produced? | [Build](build-and-observability.md), [logging](logging.md) |

For configuration and daily use, start with [usage docs](../docs/README.md).
Architecture pages are repository references, not part of `hatfield_docs`.

## Process map

```mermaid
flowchart TB
    TUI["TUI process: AgentSessionClient"] <-->|"JSONL stdin/stdout: commands, ACKs, runtime events"| Controller["Session controller: agent --controller"]
    Controller -->|Supervises| Broker["Session sqlite-queue broker + async SQLite helper"]
    Controller -->|Supervises| RC["run_control consumer"]
    Controller -->|Supervises| LLM["llm consumer: handler Fibers"]
    Controller -->|Supervises| Tool["Shared tool consumer: handler Fibers"]
    Controller -->|Supervises| MCP["mcp consumer"]
    Controller -->|Supervises| Scheduler["scheduler_default consumer"]
    Controller -.->|"Only when extension-agent handlers exist"| Extension["extension_agent consumer"]
    Controller -->|"Commands via in-process client"| Broker
    RC <-->|"run_control queue: commands, results, transitions"| Broker
    LLM <-->|"llm queue"| Broker
    Tool <-->|"tool + agent + code_mode_requests queues"| Broker
    MCP <-->|"mcp queue"| Broker
    Extension <-->|"extension_agent queue"| Broker
    Scheduler -->|"Recurring messages, e.g. extension jobs to tool"| Broker
    RC --> Events[("events.jsonl")]
    RC --> State[("state.sqlite operational rows")]
    RC -.->|"stdout: committed runtime events"| Controller
    LLM -.->|"stdout: sequence-0 deltas"| Controller
    Tool --> Scripts["Bash / PHP subprocesses"]
    LLM --> Providers["Model providers"]
    MCP --> Servers["MCP servers"]
```

Parent and child runs share these consumers; a child does not get a new controller.
Every consumer writes runtime events to its stdout; the controller polls those
pipes and forwards them to the TUI. Queue results return to `run_control`, the
only process that changes run state. The scheduler generates recurring messages;
it is not the durable deadline queue. See
[scheduling](code-mode-and-scheduling.md#scheduling) for its dispatch path.

## Code boundaries

```mermaid
flowchart LR
    TUI["src/Tui"] --> App["src/CodingAgent"]
    TUI --> Core["src/AgentCore"]
    TUI --> API["ExtensionApi"]
    App --> Core
    App --> API
    App --> Bridge["src/Platform provider bridge"]
    Packages["Extension packages and built-in extensions"] --> API
    App -.->|"AgentCommand and SetupCommand bootstrap only"| TUI
```

[depfile.yaml](../depfile.yaml) and `castor deptrac` define the exact allowed
edges. TUI directories organize presentation code; they are not separate
dependency boundaries. Commands and screens may use owning application services
directly. AgentCore has no CodingAgent, TUI or concrete-extension dependency.
ExtensionApi exposes public contracts, not host implementation services.
Session ID completion reads the session store’s bounded catalog directly;
settings path completion loads the effective layers for the active working
directory when `/settings-show` completion is requested.

`Tui\Command\ModelCommandHandler` handles `/model` and `/model-favourites`;
`ModelControlListener` registers both commands. The child-run picker uses the
same `SessionAgentArtifactPathResolver` as artifact storage, through its narrow
`ChildAgentEventsPathResolverInterface`.

## Event channels

| Channel | Authority |
|---|---|
| Canonical `RunEvent`, positive sequence | Append-only `events.jsonl`: history and replay |
| Mapped runtime event | Delivery/projection of committed events |
| Streaming delta, sequence `0` | Transient text, reasoning and tool arguments; never replay authority |
| Messenger envelope | Work delivery through queues, not conversation history |

A session's `session_id` equals its `run_id`. State transitions belong to
`run_control`; execution workers return results rather than mutating `RunState`.

## All architecture pages

- [Processes and queues](processes-and-queues.md) · [Runtime supervision](runtime.md)
- [Request lifecycle](request-lifecycle.md) · [State and recovery](state-and-recovery.md) · [Session runtime storage](session-runtime.md)
- [Code mode and scheduling](code-mode-and-scheduling.md) · [Tools and MCP](tools-and-mcp.md) · [Backgrounding](backgrounding.md)
- [Extensions and agents](extensions-and-agents.md) · [Agent communication](agent-communication.md)
- [Context and projection](context-and-projection.md) · [History conversion](history-conversion.md) · [TUI](tui.md)
- [ORM freshness](orm-freshness-map.md) · [Build and observability](build-and-observability.md) · [Logging](logging.md)

Sources: [HeadlessController](../src/CodingAgent/Runtime/Controller/HeadlessController.php),
[ConsumerSupervisor](../src/CodingAgent/Runtime/Controller/ConsumerSupervisor.php),
[Messenger routes](../config/packages/messenger.yaml), [depfile.yaml](../depfile.yaml).
