# Application\Orchestrator

Top-level orchestrators for the agent loop.

## AgentRunner

**Implements:** `AgentRunnerInterface`

Thin facade that translates public API calls into bus messages dispatched via `MessageBusInterface` (command bus):

| API Method | Bus Message |
|-----------|-------------|
| `start(input)` | `StartRun` |
| `continue(runId)` | `AdvanceRun` |
| `steer(runId, msg)` | `ApplyCommand(kind='steer')` |
| `followUp(runId, msg)` | `ApplyCommand(kind='follow_up')` |
| `cancel(runId, reason)` | `ApplyCommand(kind='cancel')` |
| `answerHuman(runId, qId, answer)` | `ApplyCommand(kind='human_response')` |

Generates `stepId` via `hrtime(true)`, `idempotencyKey` via sha256(runId|stepId).

## RunOrchestrator

**Central CQRS processor** — the heart of the agent loop.

Messenger handlers on `agent.command.bus`:
- `onStartRun(StartRun)` — create run, commit run_started event
- `onApplyCommand(ApplyCommand)` — route command, commit applied/rejected event
- `onAdvanceRun(AdvanceRun)` — bump turn, emit ExecuteLlmStep effect
- `onLlmStepResult(LlmStepResult)` — process LLM response, resolve tool execution policy per tool, dispatch enriched `ExecuteToolCall` effects with mode/timeout/maxParallelism/assistantMessage/argSchema, emit `TOOL_EXECUTION_START` lifecycle events
- `onToolCallResult(ToolCallResult)` — collect into batch via `ToolBatchCollector`, on stale/cancelled emit `stale_result_ignored`, on complete emit `TOOL_EXECUTION_END`/`MESSAGE_START`/`MESSAGE_END`/`tool_batch_committed`, detect interrupt → transition to `WaitingHuman`

**Stage 06 additions:**
- **Tool mode selection**: `resolveToolPolicy()` delegates to `ToolExecutionPolicyResolver` for per-tool mode/timeout/maxParallelism
- **Lifecycle events**: `TOOL_EXECUTION_START` (per tool call dispatch), `TOOL_EXECUTION_END` (per result), `MESSAGE_START`/`MESSAGE_END` (per tool result message), `tool_batch_committed` (batch complete)
- **Interrupt → WaitingHuman**: When tool result contains `kind=interrupt` payload, run transitions to `WaitingHuman` status with `waiting_human` event carrying question prompt/schema
- **Stale-on-cancel**: Results arriving after cancellation or from stale steps are tracked as `stale_result_ignored` events

**Flow per message:**
1. `runLockManager->synchronized()` — per-run mutex
2. `idempotency->wasHandled()` — dedup check
3. Load `RunState` from store
4. `reducer->reduce()` or inline state transition
5. Build `RunEvent` for audit trail
6. `commit()` — CAS save + event store append + outbox project
7. Boundary hooks via `hookDispatcher`
8. Dispatch effects via `stepDispatcher`
