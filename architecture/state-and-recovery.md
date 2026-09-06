# State and recovery

[Architecture map](README.md) · [Request lifecycle](request-lifecycle.md)

## A transition commits before effects execute

There is no `state.json` authority and no CAS state-file commit. Canonical append
precedes projection replacement. Projection failure invalidates the in-memory state.

```mermaid
%%{init: {"sequence": {"wrap": true, "width": 110, "actorMargin": 20, "diagramMarginX": 10, "messageMargin": 30}}}%%
sequenceDiagram
    participant RC as run_control consumer
    participant Processor as RunMessageProcessor
    participant Context as Active run context
    participant Handler as Transition handler
    participant Commit as RunCommit
    participant Events as EventStore / events.jsonl
    participant DB as Operational projection
    participant Effects as StepDispatcher
    participant Hooks as AfterTurnCommit hooks

    RC->>Processor: Command or result message
    Processor->>Processor: Acquire per-run lock
    Processor->>Context: Load state
    alt Memory cache absent or invalid
        Context->>Events: Replay canonical events
        Events-->>Context: Rebuilt RunState
    end
    Processor->>Handler: Handle against current operation identity
    alt Stale or already completed message
        Handler-->>Processor: No transition
    else Valid transition
        Handler-->>Processor: Next state, events, effects
        Processor->>Commit: commit(...)
        Commit->>Events: append or appendMany
        Events-->>Commit: Persisted events with allocated sequences
        Commit->>Context: remember(committedState)
        Context->>DB: Persist narrow operational projection
        alt Projection write fails
            Context->>Context: Invalidate memory cache
            Context-->>Commit: Propagate failure
            Note over Events,DB: Already appended canonical events remain authoritative
        else Projection succeeds
            Context->>Context: Replace in-memory RunState
            Commit->>Effects: Dispatch committed effects
            Note over Commit,Effects: Dispatch failure is logged<br/>Canonical commit is not rolled back
            Commit->>Hooks: dispatchAfterTurnCommit
            Note over Commit,Hooks: Hook failure is logged as best-effort degradation
        end
    end
```

## What persists where

```mermaid
flowchart TB
    subgraph Files["Session files"]
        Events[("sessions/id/events.jsonl<br/>canonical conversation")]
        Cursor[("sequence.cursor<br/>sequence allocation")]
        Artifacts[("parent artifacts/<br/>child handoffs and summaries")]
    end
    subgraph AppDB["Application DB · state.sqlite"]
        Catalog[(hatfield_session)]
        Projection[("run_operational_*<br/>payload-free coordination")]
        Pending[("deferred batches, questions,<br/>background process records")]
    end
    subgraph TransportDB["Separate Messenger DB"]
        Queues[("commands / effects / results / delayed messages")]
    end
    Events -->|Replay| Memory["RunState in run_control memory"]
    Events -->|Rebuild| Projection
    Events -->|Map and replay| Transcript[TUI transcript]
    Events -->|Recover missing catalog row, with limits| Catalog
    Cursor -.->|Allocates; not tail truth| Events
    Pending -->|Operational lifecycle only| Queues
    ToolOutput[("Output-cap temporary files")] -.->|"Paths referenced by notices; may expire"| Events
```

A session's `session_id` equals its `run_id`. Child sessions have their own identity.
Event logs do not recover all SQLite-only data. Renames, pending questions, deferred
batches, queues, and background records have different recovery limits.

## Resume versus explicit repair

```mermaid
%%{init: {"sequence": {"wrap": true, "width": 110, "actorMargin": 20, "diagramMarginX": 10, "messageMargin": 30}}}%%
sequenceDiagram
    actor User
    participant UI as Session picker / runtime client
    participant Owner as Controller
    participant Log as Canonical event log
    participant State as Run state and projection
    participant Repair as Repair diagnosis
    participant Queue as Execution queues

    User->>UI: /resume
    UI->>Owner: Open selected session
    Owner->>Owner: Acquire owner lock and clean disposable scopes
    UI->>Log: Load retained history
    Log-->>UI: Rebuild transcript
    State->>Log: Replay on cache miss
    Log-->>State: Restore current operation identities
    Note over Owner,Queue: Restart does not reset a claimed row's delivered_at age
    opt Work remains stranded
        User->>Repair: /repair
        Repair->>State: Diagnose current unfinished operation
        Repair-->>User: Proposed same-identity redispatch or refusal
        User->>Repair: /repair --apply
        alt Waiting for human input or required compaction request missing
            Repair-->>User: Do not redispatch unsafe or unavailable continuation
        else Reconstructable current operation
            Repair->>Queue: Fresh envelope with existing operation identity
            Note over Repair,Queue: Does not erase old claimed row or invent completion
        end
    end
```

Stored result reuse reduces repeated execution after a completed result is recorded.
It is not concurrent exclusion or an exactly-once guarantee. Check external effects
before repair. See the [transition identity matrix](../docs/session-runtime-internals.md#transition-validity).

## Compaction outcomes

```mermaid
flowchart TD
    Trigger[Manual compact or pre-LLM context threshold] --> Prepare[Prepare retained tail and compaction request]
    Prepare --> Commit[Commit current compaction operation]
    Commit --> Worker[ExecuteCompactionStep on llm]
    Worker --> Result[CompactionStepResult on run_control]
    Result --> Match{Matches current operation?}
    Match -->|No| Ignore[Ignore stale result]
    Match -->|Yes| Cancelling{Run cancelling?}
    Cancelling -->|Yes| Cancelled[Cancelled; no AdvanceRun]
    Cancelling -->|No| Good{Successful nonempty summary?}
    Good -->|Yes| Replace[Commit compacted context and retained raw tail]
    Good -->|No| Retain[Record failure; retain original messages]
    Replace --> Continue{continueAfterCompaction?}
    Retain --> Continue
    Continue -->|Yes| Running[Running and AdvanceRun]
    Continue -->|No| Completed[Complete maintenance operation]
```

## Cancellation is a request, not rollback

```mermaid
%%{init: {"sequence": {"wrap": true, "width": 110, "actorMargin": 20, "diagramMarginX": 10, "messageMargin": 30}}}%%
sequenceDiagram
    actor User
    participant TUI as CancelListener
    participant Controller
    participant RC as run_control
    participant DB as Operational status projection
    participant Worker as LLM / local tool worker
    participant MCP as External MCP call

    User->>TUI: Escape outside overlay handling
    TUI->>Controller: cancel for visible run
    TUI->>TUI: Display Cancelling
    Controller->>RC: Apply cancellation command
    RC->>RC: Commit cancelling state
    RC->>DB: Publish narrow cancellation status
    Worker->>DB: Cancellation token checks status
    Worker->>Worker: Stop supported in-flight work
    Worker->>RC: Result or cancellation outcome
    RC->>RC: Validate operation, commit terminal cancellation
    RC-->>TUI: Mapped cancellation event through controller
    Note over MCP: Current SDK integration cannot enforce arbitrary per-call cancellation
```

Escape in a child live view targets the child. Completed filesystem writes and
external side effects remain. Exiting the TUI instead invokes process shutdown.

Sources: [RunCommit](../src/AgentCore/Application/Pipeline/RunCommit.php),
[RunMessageProcessor](../src/AgentCore/Application/Pipeline/RunMessageProcessor.php),
[sessions](../docs/session-storage.md), [compaction](../docs/compaction.md),
[CancelListener](../src/Tui/Listener/CancelListener.php).
