# Runtime protocol

- Use `RuntimeEventTypeEnum` for every event type; keep mapping in the existing mapper and translator.
- Keep runtime JSONL events separate from canonical `RunEvent` storage.
- Preserve required identity keys and enum wire values unless the task explicitly changes them. Update callers and tests together, not with dual-format shims.
- Keep local TUI questions out of persisted HITL events and transcript blocks.
- Treat `subagent_progress` as structured metadata, not appended tool text.
- Preserve explicit top-level tool duration; otherwise derive it from canonical start/end timestamps, including queue time.
- Do not substitute nested executor duration for the lifecycle interval.
- Emit extension-job failure only for a validated run ID, at sequence zero. Otherwise log it; never fail the main run from that event alone.
