# Domain\Run

Run lifecycle value objects — identity, state, status, and input.

## RunId
UUID v4 value object with `generate()` factory and fallback. Stringable.

## RunHandle
Lightweight run reference — just holds `runId`. Returned by `AgentRunnerInterface::start()`.

## RunState
Aggregate root state (readonly). Properties: `runId`, `status`, `version`, `turnNo`, `lastSeq`, `isStreaming`, `streamingMessage`, `pendingToolCalls`, `errorMessage`, `messages`, `activeStepId`. Factory: `queued(runId)`.

## RunStatus
Enum: `Queued`, `Running`, `WaitingHuman`, `Cancelling`, `Completed`, `Failed`, `Cancelled`.

## StartRunInput
Input DTO: `systemPrompt`, `messages` (AgentMessage[]), optional `runId`, `metadata`.
