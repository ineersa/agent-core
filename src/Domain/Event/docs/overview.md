# Domain\Event

Event sourcing models and lifecycle management.

## RunEvent
Base event (readonly): `runId`, `seq`, `turnNo`, `type`, `payload`, `createdAt`. Extension factory validates `ext_` prefix. `isExtensionEvent()` check.

## OutboxEntry
Outbox pattern entry: `id`, `sink` (OutboxSink), `event` (RunEvent), `attempts`, `availableAt`.

## OutboxSink
Enum: `Jsonl`, `Mercure` — named projection targets.

## BoundaryHookEvent
Hook invocation event: `hookName` + mutable `context` array.

## BoundaryHookName
Constants: `BEFORE_COMMAND_APPLY`, `AFTER_COMMAND_APPLY`, `BEFORE_TURN_DISPATCH`, `AFTER_TURN_COMMIT`, `BEFORE_RUN_FINALIZE`. Extension prefix `ext:`. `isBoundary()` and `isExtensionHook()` checks.

## CoreLifecycleEventType
Defines 10 lifecycle event types: `AGENT_START/END`, `TURN_START/END`, `MESSAGE_START/UPDATE/END`, `TOOL_EXECUTION_START/UPDATE/END`. Provides `eventClassMap()` and `validateOrder()` for structural validation of event streams (enforces agent_start→agent_end, turn nesting, tool ordering, etc.).

## Lifecycle Sub-namespace
Typed subclasses of `AbstractLifecycleRunEvent` — one per lifecycle type. Each overrides `TYPE` constant.
