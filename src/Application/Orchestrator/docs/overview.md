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
- `onLlmStepResult(LlmStepResult)` — process LLM response, dispatch tool calls or finalize
- `onToolCallResult(ToolCallResult)` — collect into batch, on complete advance run

**Flow per message:**
1. `runLockManager->synchronized()` — per-run mutex
2. `idempotency->wasHandled()` — dedup check
3. Load `RunState` from store
4. `reducer->reduce()` or inline state transition
5. Build `RunEvent` for audit trail
6. `commit()` — CAS save + event store append + outbox project
7. Boundary hooks via `hookDispatcher`
8. Dispatch effects via `stepDispatcher`
