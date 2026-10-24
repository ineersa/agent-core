# Runtime protocol

- Use `RuntimeEventTypeEnum` for every event type; keep mapping in the existing mapper and translator.
- Keep runtime JSONL events separate from canonical `RunEvent` storage.
- Preserve required identity keys and enum wire values unless the task explicitly changes them. Update callers and tests together, not with dual-format shims.
- Map canonical `tool_call_acknowledged` to runtime `tool_call.acknowledged`. That freezes the original tool card as backgrounded without claiming native `tool_execution.completed`.
- Keep local TUI questions out of persisted HITL events and transcript blocks.
- Treat `subagent_progress` as structured metadata, not appended tool text.
- Preserve explicit top-level tool duration; otherwise derive it from canonical start/end timestamps, including queue time.
- Do not substitute nested executor duration for the lifecycle interval.
- Emit extension-job failure only for a validated run ID, at sequence zero. Otherwise log it; never fail the main run from that event alone.
- `protocol.error` with `payload.fatal === true` declares a terminal controller-session failure. Its `runId` may be empty. Preserve its original `payload.error` across parent, child and session-wide polling. The process client must retain this outcome outside per-run compaction, reject commands and suppress automatic restart or resume until explicit start, attach or shutdown. Other protocol errors are not fatal by default.
