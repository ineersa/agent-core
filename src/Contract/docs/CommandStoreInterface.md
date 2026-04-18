# CommandStoreInterface

**File:** `CommandStoreInterface.php`  
**Namespace:** `Ineersa\AgentCore\Contract`

## Purpose

Queue for pending commands within a run. Commands are enqueued during event processing and applied/rejected after execution.

## Methods

| Method | Signature | Description |
|--------|-----------|-------------|
| `enqueue` | `enqueue(PendingCommand $command): void` | Add a command to the pending queue. |
| `markApplied` | `markApplied(string $runId, string $idempotencyKey): void` | Mark command as successfully applied (idempotent). |
| `markRejected` | `markRejected(string $runId, string $idempotencyKey, string $reason): void` | Mark command as rejected with reason. |

## Dependencies

- `Domain\Command\PendingCommand`
