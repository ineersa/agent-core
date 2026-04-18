# ToolExecutionResultStore

**File:** `ToolExecutionResultStore.php`
**Namespace:** `Ineersa\AgentCore\Application\Handler`
**Type:** `final class`

In-memory idempotency store for tool execution results. Provides dual-keyed lookup for deduplication and replay protection.

## Key Methods

| Method | Returns | Description |
|--------|---------|-------------|
| `findByRunToolCall(string $runId, string $toolCallId)` | `?ToolResult` | Find result by run+call identity |
| `findByToolAndIdempotencyKey(string $toolName, string $toolIdempotencyKey)` | `?ToolResult` | Find result by tool+idempotency key |
| `remember(string $runId, string $toolCallId, string $toolName, ?string $toolIdempotencyKey, ToolResult $result)` | `void` | Store result under both keys |

## Behavior

- Two lookup maps: `resultsByRunToolCall` (runId|toolCallId) and `resultsByToolIdempotency` (toolName|idempotencyKey)
- `remember()` always stores by run+call; optionally stores by idempotency key when non-null
- Used by `ToolExecutor` to short-circuit duplicate execution and reuse prior results
