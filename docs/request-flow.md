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
3. Orchestration                 [RunOrchestrator::onStartRun()]
                                 [RunOrchestrator::onApplyCommand()]
                                        |
                                        v
4. State Reduction               [RunReducer::reduce()] -> Calculates Next State & Effects
                                        |
                                        | (Returns ExecuteLlmStep or ExecuteToolCall)
                                        v
5. Effect Dispatch               [RunOrchestrator::dispatchEffects()]
                                        |
       +--------------------------------+--------------------------------+
       |                                                                 |
       v                                                                 v
6a. LLM Execution                                                6b. Tool Execution
[ExecuteLlmStepWorker::__invoke()]                               [ExecuteToolCallWorker::__invoke()]
       |                                                                 |
       | (Returns LlmStepResult)                                         | (Returns ToolCallResult)
       |                                                                 |
       +--------------------------------+--------------------------------+
                                        |
                                        v
7. Result Handling               [RunOrchestrator::onLlmStepResult()]
   & Recursion                   [RunOrchestrator::onToolCallResult()]
                                        |
                                        v
8. Loop Back to #4 (State Reduction) until RunStatus is completed, cancelled, or paused.
```

## Detailed Steps

1. **Initiation (`AgentRunner`)**: The client calls `AgentRunner::start()`. This translates the request into an immutable `StartRun` message pushed to the Command Bus.
   - **Steering / Mid-Run Intervention**: A client can call `AgentRunner::steer()` on an *already running* agent. This dispatches an `ApplyCommand` to inject steering instructions dynamically into the ongoing run.
   - **HITL (Human-in-the-Loop)**: If a tool requires human input, execution pauses. The client calls `AgentRunner::answerHuman()` to dispatch an `ApplyCommand(HumanResponse)` which resumes the run with the user's input.
2. **Locking & Orchestration (`RunOrchestrator`)**: Receives the command, loads the `RunState` from the database, and calls the Reducer.
3. **Pure Reduction (`RunReducer`)**: Calculates the new state (e.g., incrementing turn numbers, appending human messages) and yields side effects.
4. **Execution Workers (`Execute*Worker`)**: Asynchronously process the side effects (calling the LLM provider or executing local PHP tools).
5. **Result Handling**: The workers push results back to the Orchestrator, which loops until the agent reaches a terminal state.
