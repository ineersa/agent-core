# Request lifecycle

[Architecture](README.md) · [Processes](processes-and-queues.md) · [State](state-and-recovery.md)

## Prompt to response

```mermaid
sequenceDiagram
    actor User
    participant TUI as TUI / session client
    participant Controller
    participant RC as run_control
    participant Log as events.jsonl
    participant LLM as llm consumer / provider
    participant Tool as tool / agent / mcp consumer
    User->>TUI: Submit prompt
    TUI->>TUI: Route question, child view, command router or normal text
    TUI->>Controller: start_run / follow_up / user_message
    Controller-->>TUI: command.ack (not completion)
    Controller->>RC: StartRun or ApplyCommand
    RC->>RC: Run lock, pending-transition recovery, load state
    RC->>Log: Commit run start, or agent_command_queued plus mailbox row
    RC->>RC: AdvanceRun (coordination action)
    RC->>Log: Drain mailbox, commit turn_advanced and operation identity
    RC->>LLM: ExecuteLlmStep
    loop Streaming
        LLM-->>Controller: Transient delta, sequence 0
        Controller-->>TUI: Incremental text / reasoning / tool arguments
    end
    LLM->>RC: LlmStepResult
    alt Tool calls
        RC->>Log: Commit llm_step_completed and tool_execution_start per call
        RC->>Tool: ExecuteToolCall for admitted calls
        Tool->>RC: ToolCallResult or later deferred completion
        RC->>RC: Collect matching results in call order
        RC->>Log: Commit tool messages and tool_batch_committed
        RC->>RC: AdvanceRun, next model step
    else Final answer
        RC->>Log: Commit answer, drain stop-boundary mailbox
        RC->>Log: agent_end completed, unless queued input continues the run
    end
    RC-->>Controller: Committed runtime events on consumer stdout
    Controller-->>TUI: Finalize transcript and activity
```

The loop repeats until completion, cancellation, a human wait or failure.
`RunCommit` dispatches effects after canonical append and after the run lock is
released; the publication arrows do not imply a direct call to the controller.
Worker stdout is delivery, not history. Subagent and `agent_resume` calls run on
the `agent` queue and MCP-backed calls on `mcp`; their results and deferred
completions return to `run_control` like ordinary tool results.

## Input before execution

`SubmitListener` routes in this order:

```mermaid
flowchart TD
    Submit["Submit editor text"] --> Ready{"Session ready?"}
    Ready -->|"No, and not /reload /new /exit /quit /resume"| Hold["Keep text; show restoring status"]
    Ready -->|Yes| Question{"Active question?"}
    Question -->|Yes| Answer["Answer question (allowed live-view navigation slash commands excepted)"]
    Question -->|No| Live{"Child live view active?"}
    Live -->|"Blocked command"| Block["Show blocked-input notice"]
    Live -->|"Plain prompt"| Child["Send to the viewed child run"]
    Live -->|"Otherwise, or no live view"| Router{"Command router result?"}
    Router -->|"Shell (! prefix)"| Shell["Shell command to runtime"]
    Router -->|DispatchRuntime| Expanded["Expanded payload as a prompt"]
    Router -->|"Local result"| Local["Apply typed local effect"]
    Router -->|None| Prompt["Normal prompt"]
    Prompt --> Dispatch{"Run state"}
    Expanded --> Dispatch
    Dispatch -->|"No run handle"| Start["start_run, promote draft"]
    Dispatch -->|"Cancelling or compacting"| Queue["Queue locally until settled"]
    Dispatch -->|Active| Steer["steer, sent as user_message"]
    Dispatch -->|"Idle or terminal"| Follow["follow_up"]
```

- `CommandParser` treats text starting with an existing file or directory path
  as a normal prompt, not a slash command.
- On the runtime, steer and follow-up are queued in the pending-command mailbox
  while the run is running, cancelling or compacting, and drained at the next
  stop boundary or turn start. A non-active run gets an immediate `AdvanceRun`.
- Normal input becomes a transcript row from its canonical event, not an
  optimistic duplicate added by the screen.

## Advance and failure boundaries

`AdvanceRunHandler` decides the next operation under the run lock:

```mermaid
flowchart TD
    A["AdvanceRun"] --> Stale{"A different operation already active?"}
    Stale -->|Yes| Noop["No-op"]
    Stale -->|No| Pending{"Unresolved tool calls?"}
    Pending -->|Yes| Noop
    Pending -->|No| Cancel{"Status cancelling?"}
    Cancel -->|Yes| Terminal["agent_end cancelled, status cancelled, then a follow-up AdvanceRun"]
    Cancel -->|No| Drain["Drain turn-start mailbox commands"]
    Drain --> Revive{"Terminal or waiting human?"}
    Revive -->|"Yes, no applied message or continue"| Book["Commit boundary events only"]
    Revive -->|"No, or revived to running"| Compact{"Compact command drained, or compacting?"}
    Compact -->|Yes| Hold["Commit boundary events; compaction drives the next AdvanceRun"]
    Compact -->|No| Guard{"Pre-LLM compaction guard?"}
    Guard -->|Yes| Req["context_compaction_requested plus CompactRun continuing after compaction"]
    Guard -->|No| Llm["turn_advanced, history_position_set, ExecuteLlmStep"]
```

`CompactRun` runs on `run_control`, sets the run to `compacting` and sends
`ExecuteCompactionStep` to the `llm` queue; `CompactionStepResult` returns to
`run_control`, which resumes with `AdvanceRun` when the compaction held a pending
turn. Results must match the active operation. Tool calls form a durable batch;
admission respects tool mode and batch capacity. Results are collected in
invocation order even when workers finish out of order. An `ask_human` result or
an in-flight human-input request moves the run to `waiting_human`; an answer
resumes it.

Before any handler runs, `RunMessageProcessor` checks that no canonical
transition is still pending, drops an `ApplyCommand` whose key is already in the
pending mailbox, and commits a separate `history_tail_discarded` event when a
context-mutating command arrives while an earlier history entry is selected.

Tool errors can be model-visible results without ending the run. Provider failure,
transport failure and ordinary tool errors are different outcomes. Compaction
failure retains original messages; cancellation wins over continuation.
See [state and recovery](state-and-recovery.md) and [tool execution](tools-and-mcp.md).

Sources: [SubmitListener](../src/Tui/Listener/SubmitListener.php),
[CommandParser](../src/Tui/Command/CommandParser.php),
[controller](../src/CodingAgent/Runtime/Controller/HeadlessController.php),
[RunMessageProcessor](../src/AgentCore/Application/Pipeline/RunMessageProcessor.php),
[AdvanceRunHandler](../src/AgentCore/Application/Pipeline/AdvanceRunHandler.php),
[ApplyCommandHandler](../src/AgentCore/Application/Pipeline/ApplyCommandHandler.php),
[LlmStepResultHandler](../src/AgentCore/Application/Pipeline/LlmStepResultHandler.php),
[ToolCallResultHandler](../src/AgentCore/Application/Pipeline/ToolCallResultHandler.php),
[RunCommit](../src/AgentCore/Application/Pipeline/RunCommit.php),
[LLM adapter](../src/AgentCore/Infrastructure/SymfonyAi/LlmPlatformAdapter.php).
