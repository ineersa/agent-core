# Context, providers, and TUI projection

[Architecture map](README.md) · [Request lifecycle](request-lifecycle.md)

## Model context construction

```mermaid
flowchart TB
    Defaults[Bundled defaults and provider catalog] --> Config[Effective configuration]
    User[User overrides] --> Config
    Project[Project overrides] --> Config
    Config --> Selection[Exact provider, model, reasoning selection]
    System[System prompt] --> Initial[Initial AgentMessage context]
    Instructions[AGENTS.md project instructions] --> Initial
    Skills[Discovered or preloaded skills] --> Initial
    Agents[Available agent definitions] --> Initial
    Prompt[Expanded real user prompt] --> Initial
    Initial --> History[RunState messages from canonical history]
    History --> Hooks[Context-transform hooks]
    Hooks --> Validate[Validate assistant tool-call and tool-result ordering]
    Validate --> Bag[Symfony AI MessageBag]
    Tools[Active per-turn toolset] --> Schema[Tool schemas]
    Bag --> Request[Platform invocation request]
    Schema --> Request
    Selection --> Request
    Request --> Route[Provider and ModelClient routing]
    Route --> Generic[Generic provider protocol]
    Route --> Codex[OpenAI Codex bridge]
    Route --> Grok[Grok bridge]
```

Configuration does not automatically enable providers. `providers:setup` is manual.
Catalog refresh updates known metadata; it does not silently add arbitrary upstream
models. Context transforms and compaction are distinct operations.

## Live deltas and canonical finalization

```mermaid
%%{init: {"sequence": {"wrap": true, "width": 110, "actorMargin": 20, "diagramMarginX": 10, "messageMargin": 30}}}%%
sequenceDiagram
    participant Provider
    participant Adapter as LlmPlatformAdapter
    participant Observer as LlmStreamDispatchObserver
    participant Stream as Stream subscribers
    participant Controller
    participant Client
    participant Poller as RuntimeEventPoller
    participant Applier as TuiRuntimeEventApplier
    participant Projector as TranscriptProjector
    participant Screen as ChatScreen
    participant Log as Canonical event log
    participant Mapper as RuntimeEventMapper / Translator

    loop Live provider response
        Provider-->>Adapter: Reasoning, text, or tool-argument chunk
        Adapter->>Observer: Stream notification
        Observer->>Stream: Typed stream dispatch
        Stream-->>Controller: Transient RuntimeEvent on worker stdout, seq 0
        Controller-->>Client: JSONL event
        Client-->>Poller: events(runId)
        Poller->>Applier: Apply event
        Applier->>Projector: accept(event)
        Projector-->>Screen: Incremental transcript change set
    end
    Note over Adapter,Log: Final worker result returns to run_control for canonical commit
    Log->>Mapper: Committed RunEvent
    Mapper-->>Controller: Mapped runtime event through worker publication
    Controller-->>Client: Final committed message and lifecycle event
    Client-->>Poller: events(runId)
    Poller->>Applier: Apply committed event
    Applier->>Projector: Finalize streamed block
    Projector-->>Screen: Complete text, reasoning, tool-call blocks
    opt Resume without live stream
        Log->>Mapper: Replay retained canonical history
        Mapper->>Applier: Historical runtime events
        Applier->>Projector: Rebuild complete transcript
        Projector-->>Screen: Initial transcript
    end
```

`RuntimeEventTranslator` consumes `RunEvent`, not `RunState`. A `run.completed` event
clears activity without adding an extra conversational answer. Sequence-0 deltas do
not become the resume source.

## Per-session TUI composition

```mermaid
flowchart TD
    Mode[InteractiveMode session-switch loop] --> Init[Initialize draft, new, or resumed session]
    Init --> Screen[Create and mount ChatScreen]
    Screen --> Factory[TuiSessionCompositionFactory]
    Factory --> Services[Fresh commands, questions, history, projectors, pollers]
    Services --> Replay[Build initial retained transcript]
    Replay --> Bind[Register session listeners and extension UI]
    Bind --> Loop[Tui event loop]
    Loop --> Input[Input and command dispatch]
    Loop --> Tick[TickPollListener]
    Tick --> Parent[RuntimeEventPoller]
    Tick --> Child[Selected child live-view polling]
    Parent --> Patches[Apply transcript and status changes]
    Child --> Patches
    Patches --> Render[ChatScreen and Symfony TUI renderer]
    Input --> Switch{Session switch requested?}
    Switch -->|Yes: stop loop, consume target| Init
    Switch -->|No| Loop
```

The screen owns widgets and focus, not the whole application service graph. Parent
and child polling have different recovery and ownership rules; they are not merely
two names for one interchangeable loop.

Sources: [InteractiveMode](../src/Tui/Application/InteractiveMode.php),
[LlmPlatformAdapter](../src/AgentCore/Infrastructure/SymfonyAi/LlmPlatformAdapter.php),
[RuntimeEventMapper](../src/CodingAgent/Runtime/Protocol/RuntimeEventMapper.php),
[TUI reference](../docs/tui-architecture.md), [provider catalog](../docs/ai-catalog.md).
