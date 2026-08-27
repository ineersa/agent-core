# Domain\Event architecture notes

`Domain\Event` defines canonical AgentCore event contracts (persisted run stream), not TUI/JSONL runtime events (`CodingAgent\Runtime\Protocol`).

## Ownership

- `RunEvent` — persisted event envelope
- `RunEventTypeEnum` — **source of truth** for all AgentCore event type strings (lifecycle, pipeline, compaction, linear-history metadata). Prefer enum cases over string literals.
- `EventFactory` — constructs typed events for handlers
- After commit, persistence goes through `EventStoreInterface` (see `RunCommit`)
- After-turn hooks: `HookDispatcher` aggregates typed `HookSubscriberInterface` subscribers in registration order (no EventDispatcher bridge)

## Lifecycle stream (ordered core)

Ordered lifecycle cases on `RunEventTypeEnum` (underscore wire values, e.g. `agent_start`):

`AgentStart` → `TurnStart` → `MessageStart`/`MessageUpdate`/`MessageEnd` → `ToolExecutionStart`/`ToolExecutionUpdate`/`ToolExecutionEnd` → `TurnEnd` → `AgentEnd`

Pipeline/compaction/history cases (`RunStarted`, `WaitingHuman`, `HistoryPositionSet`, `HistoryTailDiscarded`, compaction, etc.) live on the same enum — read the enum file for the full set. `ContextCompactionRequested` records a committed pre-LLM compaction request before its synchronous `CompactRun` effect, so replay can reject the already-consumed `AdvanceRun`. Do not maintain a second exhaustive catalog here.

Ordering constraints are enforced at each event write/commit call site; there is no separate `LifecycleOrderValidator`. Example: a standalone shell worker writes `tool_execution_*` then `AgentEnd`.

## Maintenance

When event types or subscriber contracts change, update this file and `../../Application/AGENTS.md` in the same change.
