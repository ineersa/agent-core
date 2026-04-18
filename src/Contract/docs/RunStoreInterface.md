# RunStoreInterface

**File:** `RunStoreInterface.php`  
**Namespace:** `Ineersa\AgentCore\Contract`

## Purpose

Persistence abstraction for `RunState`. Supports optimistic concurrency control.

## Methods

| Method | Signature | Description |
|--------|-----------|-------------|
| `get` | `get(string $runId): ?RunState` | Load run state by ID. Returns null if not found. |
| `compareAndSwap` | `compareAndSwap(RunState $state, int $expectedVersion): bool` | Persist state only if version matches. Returns false on conflict. |

## Concurrency

The `compareAndSwap` pattern enables lock-free concurrent writes. Callers must retry on conflict.

## Dependencies

- `Domain\Run\RunState`
