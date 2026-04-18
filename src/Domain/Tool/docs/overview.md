# Domain\Tool

Tool execution value objects for the agent loop tool system.

## Core Types

### ToolCall
A tool call: `toolCallId`, `toolName`, `arguments`, `orderIndex`, `runId`, `mode` (ToolExecutionMode), `timeoutSeconds`, `toolIdempotencyKey`, `assistantMessage` (AgentMessage), `context` (array). Enriched in Stage 06 with runtime execution context.

### ToolExecutionPolicy *(Stage 06)*
Execution policy value object: `mode` (ToolExecutionMode), `timeoutSeconds`, `maxParallelism`. Output of `ToolExecutionPolicyResolver`.

### ToolDefinition
Tool schema: `name`, `description`, `schema` (JSON schema). `toProviderPayload()` outputs LLM function-calling format.

### ToolResult
Execution result: `toolCallId`, `toolName`, `content` parts, `details`, `isError`. Stamped with execution metadata (mode, timeout, duration, idempotency info) by `ToolExecutor`.

### ToolExecutionMode
Enum: `Sequential`, `Parallel`, `Interrupt`.

## Provider Types

### ProviderRequest
LLM request override: `model`, `input`, `options`. `applyOn()` merges partial overrides onto defaults.

### ResolvedModel
Resolved model: `model` name + `options` array. Output of `ModelResolverInterface`.

### PlatformInvocationResult
Raw platform result: `assistantMessage`, `deltas`, `usage`, `stopReason`, `error`. `toArray()`.

## Hook Contexts

### BeforeToolCallContext
Pre-execution context: `assistantMessage`, `toolCall`, `args`, `context`.

### BeforeToolCallResult
Pre-execution result: `allow()` or `blocked(reason)`. `block` flag + optional reason.

### AfterToolCallContext
Post-execution context: `assistantMessage`, `toolCall`, `args`, `result`, `isError`, `context`.

### AfterToolCallResult
Post-execution result: content/details/error overrides via `withContent()`, `withDetails()`, `withIsError()`.
