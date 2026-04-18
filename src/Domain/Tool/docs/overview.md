# Domain\Tool

Tool execution value objects for the agent loop tool system.

## Core Types

### ToolCall
A tool call: `toolCallId`, `toolName`, `arguments`, `orderIndex`.

### ToolDefinition
Tool schema: `name`, `description`, `schema` (JSON schema). `toProviderPayload()` outputs LLM function-calling format.

### ToolResult
Execution result: `toolCallId`, `toolName`, `content` parts, `details`, `isError`.

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
