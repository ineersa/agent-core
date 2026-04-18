# Domain\Command

Command value objects for the agent loop command queue.

## CoreCommandKind
Constants: `steer`, `follow_up`, `cancel`, `human_response`, `continue`. Static `isCore()` check.

## PendingCommand
Queued command: `runId`, `kind`, `idempotencyKey`, `payload`, `options` (including `cancel_safe`).

## RoutedCommand
Discriminated command with factory methods: `core()`, `extension()`, `rejected()`. `isRejected()` check. Private constructor ensures proper routing status.
