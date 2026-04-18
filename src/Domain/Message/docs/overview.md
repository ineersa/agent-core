# Domain\Message

Message bus envelopes and DTOs for the agent loop message bus.

## Core Types

### AgentBusMessageInterface
Contract: `runId()`, `turnNo()`, `stepId()`, `attempt()`, `idempotencyKey()`.

### AbstractAgentBusMessage
Base implementation of the interface — shared constructor for all bus messages.

### AgentMessage
Core message DTO: `role`, `content` (content parts array), `timestamp`, `name`, `toolCallId`, `toolName`, `details`, `isError`, `metadata`. `toArray()` for serialization. `isCustomRole()` check.

### MessageBag
Immutable wrapper around `list<object>` — LLM-formatted messages. `empty()` factory.

## Bus Commands

| Command | Purpose |
|---------|---------|
| `StartRun` | Start a new run with payload |
| `AdvanceRun` | Resume/advance a run with payload |
| `ApplyCommand` | Apply a pending command (kind, payload, options) |
| `ExecuteLlmStep` | Execute LLM step (contextRef, toolsRef) |
| `ExecuteToolCall` | Execute single tool call — toolCallId, toolName, args, orderIndex, **toolIdempotencyKey, mode, timeoutSeconds, maxParallelism, assistantMessage, argSchema** (Stage 06) |
| `CollectToolBatch` | Collect completed tool results by IDs |

## Results

| Result | Purpose |
|--------|---------|
| `LlmStepResult` | LLM step outcome: assistantMessage, usage, stopReason, error |
| `ToolCallResult` | Tool call outcome: toolCallId, orderIndex, result, isError, error |

## Outbox Signals

| Signal | Purpose |
|--------|---------|
| `ProjectJsonlOutbox` | Trigger JSONL outbox projection (batchSize, retryDelay) |
| `ProjectMercureOutbox` | Trigger Mercure outbox projection (batchSize, retryDelay) |
