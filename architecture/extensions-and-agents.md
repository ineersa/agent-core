# Extensions and child agents

[Architecture map](README.md) · [Tools](tools-and-mcp.md) · [Background commands](backgrounding.md)

## Extension registration is process-local

```mermaid
%%{init: {"sequence": {"wrap": true, "width": 110, "actorMargin": 20, "diagramMarginX": 10, "messageMargin": 30}}}%%
sequenceDiagram
    participant Console
    participant Loader as ExtensionLoaderSubscriber
    participant Manager as ExtensionManager
    participant Autoload as Project extensions Composer autoload
    participant Extension as Enabled extension class
    participant API as Host ExtensionApi implementation
    participant Registry as Tools, hooks, commands, TUI registrations

    Console->>Loader: ConsoleEvents::COMMAND
    Loader->>Manager: Load enabled classes
    Manager->>Autoload: Load project extension classes
    loop Configured extensions
        Manager->>Extension: Construct extension and inject logger when supported
        Extension->>API: register(...)
        API->>Registry: Register public contracts through host adapters
        Manager->>Registry: Attach event subscriber when applicable
    end
    Note over Manager,Extension: Construction, logger injection, and subscriber attachment are not all isolated today
    Note over Console,Registry: Every console process builds its own registrations<br/>No shared PHP registry across consumers
```

Public ExtensionApi remains independent of AgentCore, CodingAgent, and in-repo TUI
classes. Packages use public contracts. The host owns adapters and runtime execution.
A throwing extension constructor can abort startup; the diagrams do not imply
transactional registration or rollback of partially registered extensions.

## Subagent, resume, and fork entry points

```mermaid
flowchart TD
    Parent[Parent model tool call] --> Kind{Tool name}
    Kind -->|subagent| Definition[Resolve named definition and task]
    Kind -->|agent_resume| Existing[Resolve parent-owned child artifact]
    Kind -->|fork| Snapshot[Capture inherited parent context and task]
    Definition --> Policy[Model, reasoning, tools, MCP, skills, extensions]
    Snapshot --> Policy
    Existing --> Resume[Continue existing child run]
    Policy --> Create[Create distinct child session and run identity]
    Create --> Deferred[Deferred batch supervision]
    Resume --> Deferred
    Deferred --> Execute[Child runs on shared controller pools]
    Execute --> Artifact[Persist parent-scoped handoff and metadata]
    Artifact --> Settle[Settle waiting parent tool call]
    Retrieve[agent_retrieve] -->|Read only| Artifact
```

| Property | Named subagent | Fork | Resume |
|---|---|---|---|
| Starting context | Agent definition, task, configured project context | Parent conversation context plus task | Existing child's retained context plus follow-up |
| Transport for launch tool | `agent` | Default `tool` | `agent` |
| Identity | New child | New child | Existing child |
| Role configuration | Named definition | Fork configuration and explicit allowed overrides | Existing child configuration |
| Completion | Deferred handoff | Deferred handoff | New handoff for continued child |

A fork is not a Git branch or worktree operation. Filesystem isolation must be chosen
by the implementation owner. Separate child identities alone do not prevent concurrent
writers from editing the same checkout.

## Durable supervision, not one controller per child

```mermaid
%%{init: {"sequence": {"wrap": true, "width": 110, "actorMargin": 20, "diagramMarginX": 10, "messageMargin": 30}}}%%
sequenceDiagram
    participant Parent as Parent run_control
    participant Launch as agent or tool consumer
    participant Prepare as Child launch preparation
    participant Batch as Deferred batch records
    participant Child as Child run on shared queues
    participant Observe as Batch observation / lifecycle handlers
    participant Artifact as Parent-owned artifacts

    Parent->>Launch: ExecuteToolCall for subagent, fork, or resume
    Launch->>Prepare: Validate launch or existing child identity
    Prepare->>Prepare: Resolve policy and construct child input
    Prepare->>Batch: Persist batch and child supervision records
    Prepare->>Child: Start child or continue existing child
    Launch-->>Parent: Deferred waiting outcome, not final handoff
    Batch->>Parent: Delayed InterruptDeferredSubagentBatchMessage at deadline
    loop Child progress
        Child->>Child: LLM and tools through shared consumer pools
        Child-->>Observe: Child lifecycle observation
        Observe->>Batch: Advance durable observation cursor
    end
    alt Child terminal outcome
        Child->>Artifact: Store handoff, metadata, bounded summaries
        Observe->>Batch: Record child settlement
        Observe->>Parent: CompleteDeferredToolCall when batch settles
        Parent->>Parent: Commit parent tool result and continue
    else Parent cancellation or deadline
        Parent->>Observe: Interrupt unfinished batch
        Observe->>Child: Request cancellation
        Observe->>Batch: Persist interruption outcome
        Observe->>Parent: Settle deferred parent continuation
    end
```

Recovery uses durable child event cursors and reads the unseen event-log suffix.
Sequence allocation can leave holes, so `sequence.cursor` is not proof of a committed
event tail. Parallel batches wait for their required child outcomes; an initial
accepted launch is not a completed child turn.

## Child tool policy and live views

```mermaid
flowchart LR
    Role[Agent definition] --> Tools[Non-MCP tool allowlist]
    Global["MCP availability: all"] --> MCP[Child MCP selection]
    Selectors["mcp selectors; mcp:- denies"] --> MCP
    Tools --> Merge[Merged candidate toolset]
    MCP --> Merge
    Merge --> Deny[Apply child exclusions and nested-launch prohibition]
    Deny --> Child[Child's actual toolset]
    Always[Always-on extensions] --> Extensions[Child extension set]
    Explicit[Definition extensions] --> Extensions
    ChildEvents[(Child events.jsonl)] --> Open[Open /agents-live]
    Open --> Rebuild[Rebuild selected child transcript]
    Rebuild --> Live[Poll selected child; route input and questions to child ID]
    Live --> Return["/agents-main or Ctrl+backslash"]
```

Optional parent extensions do not automatically propagate. Child answers must target
the child run, never whichever parent question happens to be latest. Artifacts cannot
be retrieved or resumed from an unrelated parent session.

## Extension agent jobs are a separate pipeline

```mermaid
%%{init: {"sequence": {"wrap": true, "width": 110, "actorMargin": 20, "diagramMarginX": 10, "messageMargin": 30}}}%%
sequenceDiagram
    participant Hook as Extension hook, such as after-turn commit
    participant Dispatcher as ExtensionAgentJobDispatcher
    participant Queue as extension_agent transport
    participant Runner as Public extension agent runner
    participant Model as Isolated model invocation
    participant Package as Extension-owned storage
    participant UI as Runtime status event

    Hook->>Dispatcher: Submit extension job payload
    Dispatcher->>Queue: ExtensionAgentJobMessage
    Note over Dispatcher,Queue: Sync transport is refused<br/>Model work must not run inline in the hook
    Queue->>Runner: Invoke registered extension job handler
    Runner->>Model: Extension context and private tools
    Model-->>Runner: Job outcome
    Runner->>Package: Persist extension result
    opt Job fails after Messenger retries
        Queue-->>UI: Surface exhausted-job failure
    end
```

Observational memory owns its observer and reflector data inside its package. Its
jobs are not named subagents, and their results do not universally return as
`ToolCallResult` to the parent conversation.

Sources: [agents](../docs/agents.md), [agent settings](../docs/settings-agents.md),
[ExtensionManager](../src/CodingAgent/Extension/ExtensionManager.php),
[public Extension API](../.hatfield/extensions/extension-api/docs/extension-api.md),
[Messenger configuration](../config/packages/messenger.yaml).
