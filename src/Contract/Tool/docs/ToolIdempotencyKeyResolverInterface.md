# ToolIdempotencyKeyResolverInterface

**File:** `ToolIdempotencyKeyResolverInterface.php`
**Namespace:** `Ineersa\AgentCore\Contract\Tool`
**Type:** `interface`

Optional stronger idempotency key contract. When implemented and injected into `ToolExecutor`, provides a deterministic idempotency key from a `ToolCall` for result deduplication across runs.

## Methods

| Method | Signature | Description |
|--------|-----------|-------------|
| `resolveToolIdempotencyKey` | `resolveToolIdempotencyKey(ToolCall $toolCall): ?string` | Resolve an idempotency key for the given tool call |

## Usage

- Injected optionally into `ToolExecutor` constructor
- Called when `ToolCall::toolIdempotencyKey` is not already set
- Enables external key resolution strategies (e.g. content-hash, business-key)
- Returns `null` to skip idempotency-based dedup for a given call
