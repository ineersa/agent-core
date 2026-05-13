# Agentic Loop Request Flow

This document details the lifecycle of a request from initialization through the core agentic loop.

## The Loop Flow Diagram

```text
1. API / Client                  2. Command Bus
   [AgentRunner::start()] ------> [StartRun]
   [AgentRunner::steer()] ------> [ApplyCommand(Steer)] (Mid-run interruption)
   [AgentRunner::answerHuman()] -> [ApplyCommand(HumanResponse)] (HITL)
                                        |
                                        v
3. Bus Entrypoint                [RunOrchestrator::on*()]
   (root tracing spans)                    |
                                        v
4. Shared Processing             [RunMessageProcessor]
   (lock + idempotency + load + handler routing)
                                        |
                                        v
5. Message Handler               [StartRunHandler / ApplyCommandHandler /
   Transition Build               AdvanceRunHandler / LlmStepResultHandler /
                                  ToolCallResultHandler]
                                        |
                                        v
6. Durable Commit Unit           [RunCommit::commit()]
   (CAS + event append + outbox projection + replay + effect dispatch)
                                        |
       +--------------------------------+--------------------------------+
       |                                                                 |
       v                                                                 v
7a. LLM Execution                                                7b. Tool Execution
[ExecuteLlmStepWorker::__invoke()]                               [ExecuteToolCallWorker::__invoke()]
       |                                                                 |
       | (Returns LlmStepResult)                                         | (Returns ToolCallResult)
       |                                                                 |
       +--------------------------------+--------------------------------+
                                        |
                                        v
8. Loop Back                       New results are dispatched to
                                   RunOrchestrator::onLlmStepResult()/onToolCallResult()
                                   until RunStatus becomes completed, cancelled, failed,
                                   or waiting_human.
```

## Detailed Steps

1. **Initiation (`AgentRunner`)**: The client calls `AgentRunner::start()`. This translates the request into an immutable `StartRun` message pushed to the command bus.
   - **Steering / Mid-Run Intervention**: `AgentRunner::steer()` dispatches `ApplyCommand(steer)`.
   - **HITL (Human-in-the-Loop)**: `AgentRunner::answerHuman()` dispatches `ApplyCommand(human_response)` to resume paused runs.
2. **Entrypoint (`RunOrchestrator`)**: Receives bus messages and opens root tracing spans (`command.*`, `turn.*`).
3. **Shared Runtime (`RunMessageProcessor`)**: Applies lock + idempotency boundaries, loads current `RunState`, routes to the dedicated handler, commits, runs post-commit actions, and marks handled messages.
4. **Message-specific transition handlers**: Each handler returns a declarative `HandlerResult` (`nextState`, durable events, commit-owned effects, post-commit callbacks/effects).
5. **Durable commit (`RunCommit`)**: Persists state and events, projects outbox records, rebuilds replay hot state, dispatches durable effects, and emits commit observability.
6. **Execution workers (`Execute*Worker`)**: Execute emitted effects (LLM/tool execution) and dispatch result messages back to the command bus.
7. **Recursive loop**: Result handlers continue the loop until a terminal or paused state is reached.
