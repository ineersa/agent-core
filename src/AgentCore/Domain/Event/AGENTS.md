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

`RunEventTypeEnum` defines all AgentCore event type strings as a backed enum.

The ordered core stream cases are:

- `RunEventTypeEnum::AgentStart`
- `RunEventTypeEnum::TurnStart`
- `RunEventTypeEnum::MessageStart`
- `RunEventTypeEnum::MessageUpdate`
- `RunEventTypeEnum::MessageEnd`
- `RunEventTypeEnum::ToolExecutionStart`
- `RunEventTypeEnum::ToolExecutionUpdate`
- `RunEventTypeEnum::ToolExecutionEnd`
- `RunEventTypeEnum::TurnEnd`
- `RunEventTypeEnum::AgentEnd`

`LifecycleOrderValidator::validateOrder()` is the source of truth for ordering constraints.

## Maintenance rule

When event types, ordering rules, or subscriber contracts change, update this file and `src/Application/AGENTS.md` in the same change.