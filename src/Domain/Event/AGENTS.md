# Domain\Event architecture notes

`Domain\Event` defines event contracts and lifecycle rules.

## Event -> projector/listener map

- `RunEvent` is the canonical persisted event envelope.
- After commit, events are projected by `OutboxProjector` (application layer) into:
  - JSONL outbox sink -> `JsonlOutboxProjectorWorker` -> `RunLogWriter`
  - Mercure outbox sink -> `MercureOutboxProjectorWorker` -> `RunEventPublisher`
    - topic policy: `agent/runs/{runId}` (`RunTopicPolicy`)
    - event id: `seq`
    - event type: lifecycle `type`
    - `message_update` may be coalesced in a short publish window; `message_end` and `turn_end` are always published
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

When event types, ordering rules, projection sinks, or subscriber contracts change, update this file and `src/Application/AGENTS.md` in the same change.