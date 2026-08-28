# Final canonical run-event authority matrix

**Source revision:** `059b11c74` (Pass 4). **Scope:** the one canonical `RunEvent` schema shared by parent sessions and child artifact runs. **Privacy:** labels and field names only; no session payloads, identifiers, prompts, paths, or tool output appear here.

## Rules

- `RunEventTypeEnum` is the complete persisted vocabulary: **21 cases and 21 rows** below.
- `EventPayloadNormalizer` rejects unsupported types on canonical write and read. Removed/unknown JSONL is a loud error, not a compatibility path.
- `tool_execution_end.payload.tool_result` is the one complete terminal tool-result authority. It contains the normalized `ToolCallResult` identity/order, content, details, error/cancellation, attachment-reference, and notification source facts exactly once. `message_start`, `message_end`, receipt, and stale-result events are not canonical.
- `tool_execution_update` is canonical only for terminal child-progress snapshots. Nonterminal progress is transient runtime delivery and must not enter `events.jsonl`.
- `llm_step_completed.assistant_message` owns presentation text and tool calls. Top-level `text` and `tool_calls_count` do not exist. `run_started` keeps the exact portable launch context; no safe replacement/compression was introduced.

| Wire type | Scope | Producer / sole authority | Direct consumers | Retained fields / final policy |
|---|---|---|---|---|
| `tool_execution_start` | parent + child | LLM tool scheduler; direct shell worker | replay, runtime, repair, artifact/export | Tool identity/name/order/mode and required shell arguments. **KEEP**. |
| `tool_execution_update` | parent supervision | subagent progress appender | runtime, artifact recovery | Terminal snapshot payload only is canonical; nonterminal is transient-only. **KEEP terminal / TRANSIENT nonterminal**. |
| `tool_execution_end` | parent + child | tool result handler, repair, direct shell worker | replay, runtime, repair, deferred/artifact projection, HTML export | `payload.tool_result` normalized `ToolCallResult`; sole terminal result authority. **KEEP**. |
| `agent_end` | parent + child | run/LLM/tool/compaction handlers, repair, worker failure boundary | replay, runtime, repair, hooks/export | terminal reason and safe terminal context. **KEEP**. |
| `run_started` | parent + child | start-run handler | replay, runtime/transcript, catalog, context/history, artifact/export | exact initial messages, launch/model metadata, child linkage. **KEEP**; launch context is not duplicated safely. |
| `turn_advanced` | parent + child | advance handler; standalone-shell application | replay, runtime, repair/history | turn and bounded operation/advance identity. **KEEP**. |
| `llm_step_completed` | parent + child | LLM result handler | replay, runtime, usage/context, repair, history, export | step/stop/model/reasoning/usage, `assistant_message`, audit tool metadata. **KEEP**; text/count derive from assistant message. |
| `llm_step_failed` | parent + child | LLM result handler | replay, runtime, repair, export | sanitized failure/retry/attempt context. **KEEP**. |
| `llm_step_aborted` | parent + child | LLM handler; repair cancellation | replay, runtime, repair, usage | operation identity, stop/cancel/usage and bounded safe summary. **KEEP**. |
| `waiting_human` | shared schema (interactive parent flow) | tool result handler | replay, runtime, human-input/cancel/repair | complete request and continuation correlation. **KEEP**. |
| `agent_command_applied` | parent + child | command/shell application | replay, runtime, repair/context/history/export | accepted user/shell/HITL immutable input and ordering. **KEEP**. |
| `agent_command_rejected` | parent + child | command mailbox policy | replay, runtime, export/history | rejected kind/reason/identity. **KEEP**. |
| `agent_command_queued` | parent + child | command application | runtime, history/context, mailbox lifecycle | durable enqueue order and pending command facts. **KEEP**. |
| `tool_batch_committed` | parent + child | tool result handler; repair | replay, compaction, repair, snapshot cleanup | ordered LLM-tool completion boundary. **KEEP**. |
| `model_notification` | parent + child | model notification codec from LLM/tool paths | runtime, transcript, export | ordered notification identity/metadata/text; no duplicated full tool result. **KEEP**. |
| `context_compaction_requested` | parent + child | advance handler | replay duplicate guard, repair/compaction | consumed advance/compaction request identity. **KEEP**. |
| `context_compaction_started` | parent + child | compaction handler | replay, repair, context/runtime | exact in-flight compaction operation/prepared request. **KEEP**. |
| `context_compacted` | parent + child | compaction result/handler | replay, runtime, context/resume/export | post-compaction message checkpoint and retained history transition. **KEEP**. |
| `context_compaction_failed` | parent + child | compaction handlers | replay, repair, context/runtime | safe failure plus structural/post-start identity. **KEEP**. |
| `history_position_set` | parent + child | history/advance/shell services | history projector/filter/replay, export | selected tip/prior tip/reason. **KEEP**. |
| `history_tail_discarded` | parent + child | history-tail discard service | history projector/filter/replay | logical tail discard boundary/reason. **KEEP**. |

## Breaking-removal appendix

The following ten former wire types are absent from the matrix and from all active writers/readers: `agent_start`, `turn_start`, `message_start`, `message_update`, `message_end`, `turn_end`, `model_changed`, `agent_command_superseded`, `tool_call_result_received`, and `stale_result_ignored`. Their old logs are intentionally unsupported; canonical decoding fails loudly. Noncanonical local command-policy names containing `turn_start` are not persisted run events.

## Verification

A report/enum structural check must compare the enum case count with the 21 table rows. Source and extension residue checks must search raw wire strings as well as enum references, because extensions may use wire labels directly.
