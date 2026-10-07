# Canonical events

- Use `RunEventTypeEnum` cases rather than string literals. Do not maintain a duplicate event catalog.
- Keep persisted `RunEvent` contracts separate from runtime JSONL events.
- Construct handler events through `EventFactory` and persist through `RunCommit`.
- Preserve hook registration order in `HookDispatcher`; do not add an EventDispatcher bridge.
- Context refresh must replace only generated instructions, not conversation or compaction summaries.
