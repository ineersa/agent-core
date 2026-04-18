# Application\Reducer

Pure state reducer — the decision engine of the agent loop.

## RunReducer

Maps `(RunState, command)` → `ReduceResult(newState, effects)`. No side effects — pure function.

### Handled commands:

| Command | State Change | Effects |
|---------|-------------|---------|
| `StartRun` | Status → Running, hydrate messages from payload | none |
| `AdvanceRun` | Bump turnNo, status → Running (if not terminal) | `[ExecuteLlmStep]` |
| `ApplyCommand` | cancel → Cancelling, steer/follow_up → append message, human_response → Running | none |

### Message hydration
`hydrateMessage()` converts serialized payload arrays into `AgentMessage` value objects — validates types defensively.

## ReduceResult

`ReduceResult(state: RunState, effects: list<object>)` — new state + zero or more effect commands to dispatch asynchronously.
