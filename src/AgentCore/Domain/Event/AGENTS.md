# Domain\Event architecture notes

`Domain\Event` defines event contracts and lifecycle rules.

## Event -> listener map

- `RunEvent` is the canonical persisted event envelope.
- After commit, events are persisted through `EventStoreInterface`.
- In-process listeners consume events through:
  - `RunEventDispatcher`
  - `EventSubscriberRegistry`
  - extension subscribers implementing `EventSubscriberInterface`

## Core lifecycle taxonomy

`CoreLifecycleEventType` defines the ordered core stream:

- `agent_start`
- `turn_start`
- `message_start`
- `message_update`
- `message_end`
- `tool_execution_start`
- `tool_execution_update`
- `tool_execution_end`
- `turn_end`
- `agent_end`

The `CoreLifecycleEventType::validateOrder()` method is the source of truth for ordering constraints.

## Maintenance rule

When event types, ordering rules, or subscriber contracts change, update this file and `src/Application/AGENTS.md` in the same change.