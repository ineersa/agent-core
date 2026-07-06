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
- `CompactRun` -> `RunOrchestrator::onCompactRun()` on `agent.command.bus`
- `CompactionStepResult` -> `RunOrchestrator::onCompactionStepResult()` on `agent.command.bus`
- `ExecuteCompactionStep` -> `ExecuteCompactionStepWorker::__invoke()` on `agent.execution.bus`

Note: `CollectToolBatch` is routed to `agent.execution.bus` in `config/messenger.php`, but there is currently no `AsMessageHandler` consumer for this message in `src/`.

## Message -> dispatched-by / handled-by

- `StartRun`
  - dispatched by: `AgentRunner::start()`
  - handled by: `RunOrchestrator::onStartRun()` -> `RunMessageProcessor` -> `StartRunHandler`
- `ApplyCommand`
  - dispatched by: `AgentRunner::continue()/steer()/followUp()/cancel()/answerHuman()` via `applyCoreCommand()`
  - handled by: `RunOrchestrator::onApplyCommand()` -> `RunMessageProcessor` -> `ApplyCommandHandler`
- `AdvanceRun`
  - dispatched by: `StartRunHandler` (initial post-commit kickoff), `ApplyCommandHandler` and `LlmStepResultHandler` follow-up callbacks, plus `AgentLoopResumeStaleRunsCommand::execute()`
  - handled by: `RunOrchestrator::onAdvanceRun()` -> `RunMessageProcessor` -> `AdvanceRunHandler`
- `ExecuteLlmStep`
  - dispatched by: `AdvanceRunHandler` through `RunMessageProcessor`/`RunCommit` effect dispatch
  - handled by: `ExecuteLlmStepWorker::__invoke()`
- `ExecuteToolCall`
  - dispatched by: `LlmStepResultHandler` through `RunMessageProcessor`/`RunCommit` effect dispatch
  - handled by: `ExecuteToolCallWorker::__invoke()`
- `LlmStepResult`
  - dispatched by: `ExecuteLlmStepWorker::__invoke()`
  - handled by: `RunOrchestrator::onLlmStepResult()` -> `RunMessageProcessor` -> `LlmStepResultHandler`
- `ToolCallResult`
  - dispatched by: `ExecuteToolCallWorker::__invoke()`
  - handled by: `RunOrchestrator::onToolCallResult()` -> `RunMessageProcessor` -> `ToolCallResultHandler`
- `CompactRun`
  - dispatched by: runtime/TUI compaction trigger (COMP-03)
  - handled by: `RunOrchestrator::onCompactRun()` -> `RunMessageProcessor` -> `CompactRunHandler`
- `ExecuteCompactionStep`
  - dispatched by: `CompactRunHandler` through `RunMessageProcessor`/`RunCommit` effect dispatch
  - handled by: `ExecuteCompactionStepWorker::__invoke()`
- `CompactionStepResult`
  - dispatched by: `ExecuteCompactionStepWorker::__invoke()`
  - handled by: `RunOrchestrator::onCompactionStepResult()` -> `RunMessageProcessor` -> `CompactionStepResultHandler`

## Event -> listener (application side)

- `RunCommit::commit()` owns durable persistence and commits `RunEvent` instances through `EventStoreInterface`.
- In-process event dispatch goes through `RunEventDispatcher` + `EventSubscriberRegistry`.
- Extension event listeners are provided through `agent_loop.extension.event_subscriber` tagged services.

## Observability wiring

- `RunOrchestrator` wraps command and turn processing in `RunTracer` root spans (`command.*`, `turn.*`), `RunMessageProcessor` owns lock/idempotency/handler dispatch, and `RunCommit` emits `persistence.commit` spans for durable commit work.
- Commit persistence failures are surfaced through structured warnings (`agent_loop.commit.*`) and state rollback is attempted when event persistence fails before commit finalization.
- `ExecuteLlmStepWorker` and `ExecuteToolCallWorker` emit execution spans (`llm.call`, `tool.call`) and feed latency/error metrics.
- `RunMetrics` tracks active runs by status, turn-duration histogram, LLM/tool latency/error rates, command queue lag, stale-result count, and replay rebuild counters.
- `HotPromptStateRebuilderInterface (SessionHotPromptReplayService in App)` increments rebuild counters and contributes replay tracing for hot-state rebuild operations.
- `RunDebugService` exposes the current metrics snapshot for `agent-loop:run-inspect` output.

## Turn tree replay

Branch turn-tree projection and active-path filtering live in **CodingAgent session**
(`CodingAgent\Session\TurnTree`, `CodingAgent\Session\Replay`). AgentCore emits
canonical events (`turn_advanced`, `leaf_set`, `parent_turn_no`, etc.) and replays
through narrow contracts (`BranchReplayFilterInterface`, `TurnTreeProjectorInterface`
under `AgentCore\Contract\TurnTree`). See `docs/session-storage.md` "Turn tree model".

Core replay integration:
- `RunStateRebuilderInterface` (`SessionRunStateReplayService` in App) — optional `BranchReplayFilterInterface` before reducing into `RunState`;
  integrity checks use the full canonical stream, not the filtered stream.
- `HotPromptStateRebuilderInterface (SessionHotPromptReplayService in App)` — optional branch filter before replaying prompt messages; integrity from full stream.
- `RunRewindServiceInterface` (`SessionRewindService` in App) — uses `TurnTreeProjectorInterface` to validate rewind targets.

## Maintenance rule

When routing, handlers, projector flow, or subscriber contracts change, update this file in the same change.