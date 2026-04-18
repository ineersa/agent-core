# PromptStateStoreInterface

**File:** `PromptStateStoreInterface.php`  
**Namespace:** `Ineersa\AgentCore\Contract`

## Purpose

Hot prompt state persistence — caches the current prompt context for a run to avoid full event replay on each turn.

## Methods

| Method | Signature | Description |
|--------|-----------|-------------|
| `get` | `get(string $runId): ?array` | Load cached prompt state. Returns null if not cached. |
| `save` | `save(string $runId, array $state): void` | Persist prompt state snapshot. |
| `delete` | `delete(string $runId): void` | Remove cached state (on run completion or TTL expiry). |

## Notes

- The state array structure is opaque to the store — it's managed by the application layer.
- TTL-based cleanup is handled by the infrastructure layer.
