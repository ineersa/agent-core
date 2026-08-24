Main idea

The assistant response does not return through one synchronous call stack. The runtime is event-driven:

1. User input becomes a command.
2. AgentCore commits state/events.
3. LLM and tools run on separate Messenger workers.
4. Results return to AgentCore.
5. Runtime events stream back to the TUI.
6. The TUI projects those events into transcript blocks.

The default and authoritative path is --transport=process.

End-to-end sequence

 ```mermaid
   sequenceDiagram
       actor User
       participant Editor as PromptEditor / ChatScreen
       participant Submit as SubmitListener
       participant Client as JsonlProcessAgentSessionClient
       participant Controller as HeadlessController
       participant RunQ as run_control worker
       participant Core as AgentCore pipeline
       participant LlmQ as llm worker
       participant Adapter as LlmPlatformAdapter
       participant Provider as Symfony AI Provider / ModelClient
       participant ToolQ as tool / agent / mcp worker
       participant Tool as Toolbox tool
       participant Poller as RuntimeEventPoller
       participant Projector as TranscriptProjector

       User->>Editor: Enter prompt
       Editor->>Submit: SubmitEvent
       Submit->>Submit: route question / slash / shell / normal prompt

       alt First user message
           Submit->>Client: start(StartRunRequest)
           Client->>Controller: start_run JSONL
           Controller-->>Client: command.ack
           Controller->>RunQ: StartRun
       else Idle/completed run
           Submit->>Client: send(follow_up)
           Client->>Controller: follow_up JSONL
           Controller->>RunQ: ApplyCommand(FollowUp)
       else Active run
           Submit->>Client: send(steer)
           Client->>Controller: user_message JSONL
           Controller->>RunQ: ApplyCommand(Steer)
       end

       RunQ->>Core: RunOrchestrator → RunMessageProcessor
       Core->>Core: lock, rebuild state, handler, CAS commit
       Core->>Core: append canonical events.jsonl
       Core->>LlmQ: ExecuteLlmStep

       LlmQ->>Adapter: invoke(ModelInvocationRequest)
       Adapter->>Adapter: load messages, hooks, tools, validate history
       Adapter->>Provider: Platform::invoke(..., stream=true)

       loop Provider stream
           Provider-->>Adapter: thinking/text/tool-call delta
           Adapter-->>Controller: transient RuntimeEvent, seq=0
           Controller-->>Client: JSONL event
           Client-->>Poller: events(runId)
           Poller->>Projector: accept(event)
           Projector-->>Editor: incremental transcript update
       end

       Provider-->>Adapter: stream finished
       Adapter-->>LlmQ: PlatformInvocationResult
       LlmQ->>RunQ: LlmStepResult

       alt Assistant requested tools
           RunQ->>Core: LlmStepResultHandler
           Core->>Core: commit assistant + pending tool calls
           Core->>ToolQ: ExecuteToolCall(s)
           ToolQ->>Tool: FaultTolerantToolbox::execute()
           Tool-->>ToolQ: ToolResult
           ToolQ->>RunQ: ToolCallResult
           RunQ->>Core: collect complete ordered tool batch
           Core->>Core: append role=tool messages
           Core->>LlmQ: AdvanceRun → next ExecuteLlmStep
           Note over LlmQ,Provider: Model now receives assistant tool_calls + tool results
       else No tool calls
           RunQ->>Core: commit LlmStepCompleted + AgentEnd(completed)
           Core-->>Controller: canonical RuntimeEvents, seq>0
           Controller-->>Client: assistant.message_completed + run.completed
           Client-->>Poller: events(runId)
           Poller->>Projector: finalize assistant blocks
           Projector-->>Editor: final response displayed, Working cleared
       end
 ```

Core agent loop

 ```mermaid
   flowchart TD
       A[StartRun / FollowUp / Steer] --> B[Apply user message to RunState]
       B --> C[AdvanceRunHandler]
       C --> D[ExecuteLlmStep]
       D --> E[Provider streaming request]
       E --> F[LlmStepResult]

       F --> G{Tool calls?}
       G -- No --> H[LlmStepCompleted]
       H --> I[AgentEnd: completed]

       G -- Yes --> J[Commit assistant message<br/>with tool_calls]
       J --> K[ExecuteToolCall for each call]
       K --> L[Collect ordered ToolCallResults]
       L --> M[Append role=tool messages]
       M --> C

       F --> N{Human input required?}
       N -- Model-turn ask_human --> O[WaitingHuman]
       N -- Tool approval/suspension --> P[WaitingHuman with exact tool continuation]
       O --> Q[answer_human adds model-visible response]
       Q --> C
       P --> R[answer_human redrives exact ExecuteToolCall]
       R --> L
 ```

Exact stages

### 1. TUI boot

src/CodingAgent/CLI/AgentCommand.php

- AgentCommand::__invoke() defaults to transport=process.
- runTui() resolves JsonlProcessAgentSessionClient.
- InteractiveMode::run() creates:
    - Tui
    - ChatScreen
    - session state
    - transcript projector
    - pollers
    - listeners
- It focuses the prompt editor and starts Tui::run().

### 2. Input submission

src/Tui/Listener/SubmitListener.php::register()

A Symfony TUI SubmitEvent triggers:

 ```text
   ChatScreen::extract()
       → active question interception?
       → subagent live-view routing?
       → slash/shell command routing?
       → otherwise dispatchToRuntime()
 ```

Normal prompts are deliberately not echoed locally. The canonical runtime event later creates the user transcript block.

### 3. First message versus later messages

SubmitListener::dispatchToRuntime():

| Current state         | Action                                             |
| --------------------- | -------------------------------------------------- |
| No run handle         | Build StartRunRequest, call client->start()        |
| Active run            | Send UserCommand(type: steer)                      |
| Idle/completed run    | Send UserCommand(type: follow_up)                  |
| Cancelling/compacting | Queue locally, send follow-up after terminal event |

A draft session is promoted to a real DB/session ID before the first request.

### 4. Process transport boundary

src/CodingAgent/Runtime/Process/JsonlProcessAgentSessionClient.php

The process client launches:

 ```text
   bin/console agent --controller
 ```

and communicates over:

 ```text
   TUI stdin  → controller stdin:  RuntimeCommand JSONL
   controller stdout → TUI stdout: RuntimeEvent JSONL
 ```

HeadlessController owns these workers:

 ```text
   run_control  State-machine commands/results
   llm          Provider calls
   tool         Generic tools
   agent        Subagent tool calls
   mcp          MCP-backed tool calls
 ```

### 5. Controller command translation

src/CodingAgent/Runtime/Controller/CommandHandler/

Examples:

 ```text
   start_run   → StartRunHandler → InProcessAgentSessionClient::start()
   follow_up   → UserMessageHandler → InProcessAgentSessionClient::send()
   user_message/steer → UserMessageHandler
 ```

Despite its name, InProcessAgentSessionClient here means “invoke AgentCore from inside the controller process.” The TUI and controller are still separate processes.

### 6. Initial prompt construction

src/CodingAgent/Runtime/InProcess/InProcessAgentSessionClient.php::start()

It constructs the initial AgentMessage[] in this order:

 ```text
   1. system prompt
   2. AGENTS.md user-context
   3. skills user-context
   4. available-agent definitions user-context
   5. expanded real user prompt
 ```

It then:

- resolves the exact model and reasoning level;
- stores those in RunMetadata;
- creates StartRunInput;
- calls AgentRunner::start().

### 7. AgentCore command pipeline

src/AgentCore/Application/Pipeline/AgentRunner.php

AgentRunner::start() dispatches StartRun to agent.command.bus.

In process mode this is synchronous; in the default controller mode Messenger routes it to run_control.

RunOrchestrator sends every message through RunMessageProcessor, which:

1. acquires the per-run lock;
2. checks idempotency;
3. rebuilds stale state from canonical events;
4. selects the appropriate handler;
5. commits state with CAS;
6. appends events;
7. dispatches execution effects.

Canonical storage:

 ```text
   .hatfield/sessions/<run-id>/events.jsonl
   .hatfield/sessions/<run-id>/state.json
 ```

### 8. Scheduling the LLM request

StartRunHandler commits run_started, then schedules AdvanceRun.

AdvanceRunHandler:

- drains queued follow-up/steer messages at a safe boundary;
- optionally triggers pre-LLM compaction;
- advances the turn;
- creates ExecuteLlmStep containing:
    - exact model;
    - run ID;
    - turn number;
    - step ID;
    - context reference;
    - toolset reference.

ExecuteLlmStep is routed to the llm transport.

### 9. Building the provider request

src/AgentCore/Application/Handler/ExecuteLlmStepWorker.php

The worker calls:

 ```text
   LlmPlatformAdapter::invoke(ModelInvocationRequest)
 ```

LlmPlatformAdapter then:

1. loads current RunState.messages;
2. applies context-transform hooks;
3. validates assistant-tool-call/tool-result sequencing;
4. converts AgentMessage[] into a Symfony AI MessageBag;
5. resolves the active per-turn toolset;
6. injects actual Tool schemas;
7. requests stream=true;
8. calls Symfony AI Platform::invoke().

Symfony AI then performs:

 ```text
   ModelResolverRoutingSubscriber
       → select exact provider/model/reasoning options
       → Platform
       → Provider
       → Contract::createRequestPayload()
       → matching ModelClient::request()
       → HTTP / SSE / WebSocket provider bridge
 ```

### 10. Streaming back to the TUI

LlmPlatformAdapter::consumeStream() iterates the provider stream.

LlmStreamDispatchObserver forwards deltas to:

- AssistantThinkingStreamSubscriber
- AssistantTextStreamSubscriber
- ToolCallStreamSubscriber

They produce transient RuntimeEvents with seq=0:

 ```text
   assistant.message_started
   assistant.thinking_started
   assistant.thinking_delta
   assistant.thinking_completed
   assistant.text_started
   assistant.text_delta
   tool_call.started
   tool_call.arguments_delta
   tool_call.arguments_completed
 ```

These events are live-only and are not canonical replay data.

### 11. LLM completion handling

After the stream ends, LlmPlatformAdapter reconstructs one final AssistantMessage.

ExecuteLlmStepWorker wraps it in LlmStepResult and dispatches that back to run_control.

LlmStepResultHandler:

- appends the assistant message to RunState.messages;
- commits llm_step_completed;
- extracts tool calls;
- either completes the run or dispatches tools.

### 12. Tool execution loop

For every model tool call, AgentCore creates ExecuteToolCall.

Routing:

 ```text
   generic tool → tool worker
   subagent     → agent worker
   MCP tool     → mcp worker
 ```

ExecuteToolCallWorker builds a domain ToolCall and calls:

 ```text
   ToolExecutor
       → allowlist and idempotency checks
       → FaultTolerantToolbox
       → registered Symfony AI tool handler
       → ToolResult
 ```

Errors normally become model-visible error tool results rather than breaking the entire agent loop.

### 13. Feeding tool results back to the LLM

Each worker sends ToolCallResult to run_control.

ToolCallResultHandler:

1. collects all results for the assistant’s tool batch;
2. preserves the original model order;
3. appends one AgentMessage(role: tool) per result;
4. commits tool_execution_end and message events;
5. schedules another AdvanceRun.

The next LLM request contains:

 ```text
   assistant message with tool_calls
   tool result message 1
   tool result message 2
   ...
 ```

The loop repeats until the model emits no tool calls.

### 14. Human input and approvals

There are two continuations:

- Model-turn input such as ask_human: the answer becomes a model-visible message, then AgentCore advances to another LLM step.
- Tool-call approval/suspension: the answer is attached to the stored ExecuteToolCall, and the exact tool call is re-executed. The answer is not inserted into model context directly.

The TUI receives human_input.requested; RuntimeQuestionEventHandler opens the question overlay. Submission is intercepted by SubmitListener and sent as answer_human.

### 15. Final assistant response

When LlmStepResultHandler sees no tool calls and no queued continuation, one commit contains:

 ```text
   llm_step_completed
   agent_end(reason=completed)
 ```

RuntimeEventTranslator maps those to:

 ```text
   assistant.message_completed
   run.completed
 ```

The TUI tick path is:

 ```text
   TickPollListener
     → RuntimeEventPoller::poll()
     → AgentSessionClient::events()
     → TuiRuntimeEventApplier::apply()
     → TranscriptProjector::accept()
     → AssistantStreamProjectionSubscriber
     → ChatScreen::applyTranscriptChangeSet()
 ```

assistant.message_completed finalizes the existing streaming block. On replay, it reconstructs the complete assistant/thinking/tool-call blocks from the canonical event. run.completed clears the working state but
intentionally adds no extra transcript block.

Durable versus transient events

| Event kind                                     | Sequence | Persisted | Purpose                         |
| ---------------------------------------------- | -------- | --------- | ------------------------------- |
| Streaming thinking/text/tool arguments         | 0        | No        | Immediate live rendering        |
| User message, turn start, assistant completion | >0       | Yes       | Canonical transcript and replay |
| Tool execution start/end                       | >0       | Yes       | Tool cards and recovery         |
| Run completed/failed/cancelled                 | >0       | Yes       | Authoritative lifecycle         |

The critical invariant is:

> Streaming events make the UI responsive; canonical events determine history, resume, and final correctness.

Most important files

 ```text
   src/Tui/Listener/SubmitListener.php
   src/Tui/Listener/TickPollListener.php
   src/Tui/Runtime/RuntimeEventPoller.php

   src/CodingAgent/Runtime/Process/JsonlProcessAgentSessionClient.php
   src/CodingAgent/Runtime/Controller/HeadlessController.php
   src/CodingAgent/Runtime/InProcess/InProcessAgentSessionClient.php
   src/CodingAgent/Runtime/Protocol/RuntimeEventTranslator.php

   src/AgentCore/Application/Pipeline/RunOrchestrator.php
   src/AgentCore/Application/Pipeline/RunMessageProcessor.php
   src/AgentCore/Application/Pipeline/AdvanceRunHandler.php
   src/AgentCore/Application/Handler/ExecuteLlmStepWorker.php
   src/AgentCore/Infrastructure/SymfonyAi/LlmPlatformAdapter.php
   src/AgentCore/Application/Pipeline/LlmStepResultHandler.php
   src/AgentCore/Application/Handler/ExecuteToolCallWorker.php
   src/AgentCore/Application/Pipeline/ToolCallResultHandler.php

   config/packages/messenger.yaml
 ```

