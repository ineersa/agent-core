# Application architecture notes

This README is an architecture map (not an index).

## Command -> handler

- `StartRun` -> `RunOrchestrator::onStartRun()` on `agent.command.bus`
- `ApplyCommand` -> `RunOrchestrator::onApplyCommand()` on `agent.command.bus`
- `AdvanceRun` -> `RunOrchestrator::onAdvanceRun()` on `agent.command.bus`
- `LlmStepResult` -> `RunOrchestrator::onLlmStepResult()` on `agent.command.bus`
- `ToolCallResult` -> `RunOrchestrator::onToolCallResult()` on `agent.command.bus`
- `ExecuteLlmStep` -> `ExecuteLlmStepWorker::__invoke()` on `agent.execution.bus`
- `ExecuteToolCall` -> `ExecuteToolCallWorker::__invoke()` on `agent.execution.bus`
- `ProjectJsonlOutbox` -> `JsonlOutboxProjectorWorker::__invoke()` on `agent.publisher.bus`
- `ProjectMercureOutbox` -> `MercureOutboxProjectorWorker::__invoke()` on `agent.publisher.bus`

Note: `CollectToolBatch` is routed to `agent.execution.bus` in `config/messenger.php`, but there is currently no `AsMessageHandler` consumer for this message in `src/`.

## Message -> dispatched-by / handled-by

- `StartRun`
  - dispatched by: `AgentRunner::start()` (used by `RunApiController::startRun()`)
  - handled by: `RunOrchestrator::onStartRun()`
- `ApplyCommand`
  - dispatched by: `AgentRunner::continue()/steer()/followUp()/cancel()/answerHuman()` via `applyCoreCommand()`
  - dispatched by: `RunApiController::sendCommand()` for HTTP command submission
  - handled by: `RunOrchestrator::onApplyCommand()`
- `AdvanceRun`
  - dispatched by: `RunOrchestrator::dispatchAdvance()`, `AgentLoopResumeStaleRunsCommand::execute()`
  - handled by: `RunOrchestrator::onAdvanceRun()`
- `ExecuteLlmStep`
  - dispatched by: `RunReducer::onAdvanceRun()` and `RunOrchestrator` effect-building paths
  - handled by: `ExecuteLlmStepWorker::__invoke()`
- `ExecuteToolCall`
  - dispatched by: `RunOrchestrator::onLlmStepResult()` tool-call effect generation
  - handled by: `ExecuteToolCallWorker::__invoke()`
- `LlmStepResult`
  - dispatched by: `ExecuteLlmStepWorker::__invoke()`
  - handled by: `RunOrchestrator::onLlmStepResult()`
- `ToolCallResult`
  - dispatched by: `ExecuteToolCallWorker::__invoke()`
  - handled by: `RunOrchestrator::onToolCallResult()`

## Event -> projector/listener (application side)

- `RunOrchestrator::commit()` projects all committed `RunEvent` instances through `OutboxProjector::project()`.
- `OutboxProjector` enqueues each event into:
  - `OutboxSink::Jsonl` (consumed by `JsonlOutboxProjectorWorker` -> `RunLogWriter`)
  - `OutboxSink::Mercure` (consumed by `MercureOutboxProjectorWorker` -> `RunEventPublisher`)
- In-process event dispatch goes through `RunEventDispatcher` + `EventSubscriberRegistry`.
- Extension event listeners are provided through `agent_loop.extension.event_subscriber` tagged services.

## Maintenance rule

When routing, handlers, projector flow, or subscriber contracts change, update this file in the same change.