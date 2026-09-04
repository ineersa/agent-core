# Runtime protocol (JSONL / TUI wire)

Stable **runtime** event contract for TUI projection and process JSONL transport. Distinct from AgentCore persisted `RunEvent` / `RunEventTypeEnum` (Domain).

## Source of truth

- Every `RuntimeEvent.type` MUST be a `RuntimeEventTypeEnum` case — **read the enum** for the full catalog (`RuntimeEventTypeEnum.php`). This file records ownership and non-obvious payload invariants only; do not restate every case here.
- Mapping/normalization: `RuntimeEventMapper`, `RuntimeEventTranslator`
- Envelope DTO: `RuntimeEvent`; commands: `RuntimeCommand`; codec: `JsonlCodec`

## Ownership invariants

- **Transient vs canonical:** stream deltas and many runtime events are TUI/JSONL-facing. Canonical session replay remains `.hatfield/sessions/<id>/events.jsonl` via `EventStoreInterface` (AgentCore `RunEvent` stream). Keep them separate.
- **HITL vs local TUI prompts:** AgentCore `waiting_human` maps to `human_input.requested` (then `human_input.answered` / related). Enum cases `approval.*` exist for the HITL family but are **currently reserved / not emitted** by the translator — live tool-approval suspensions use the human_input path with tool-call continuation metadata. Local TUI prompts (settings, confirmations) may share widget schema but must **not** become transcript blocks or persisted HITL `RuntimeEvent`s. HITL `source` is `agent_core`; `transcript` is true for HITL instances.
- **Extension agent jobs:** `extension_agent.job_failed` is JSONL/TUI-only (`seq=0`); does **not** append canonical RunEvents and does **not** alone mark the main run failed. Emit only when job `payload.run_id` is a validated non-empty scalar; missing `run_id` → structured log only. Its `message` contains the unwrapped extension handler exception message, with a generic fallback when that message is empty.
- **Subagent progress:** tool payloads may carry structured `subagent_progress` (replaces delta-append semantics in projection). Built/appended by CodingAgent subagent progress services; projected by `ToolProjectionSubscriber` / formatters — treat as structured meta, not free-form tool text.
- **Tool questions / bg process:** `tool_question.requested` and `bg_process.completed` are first-class runtime types (see enum); local tool-question flow is separate from AgentCore HITL approvals — see [approvals.md](../../../../docs/approvals.md) and [human-input.md](../../../../docs/human-input.md).

## Payload families (shapes only)

Exact optional fields evolve with mappers; assert against code + tests. Stable identity keys:

| Family | Typical keys |
|---|---|
| User message | `message_id`, `text` |
| Assistant stream | `message_id`, `content_index`, `block_id`, optional `delta`/`text`/`model`/`stop_reason` |
| Tool call/execution | `tool_call_id`, `tool_name`, optional `arguments`/`delta`/`subagent_progress`/`result`/`is_error`/`duration_ms`/`cancelled`/`timed_out` |
| Progress/status | `scope` (`model`\|`tool`\|`session`\|`compaction`), `message`, `percent`, `indeterminate` |
| HITL request | `request_id`, `source`, `question_id`, `kind`, `prompt`, `schema`, optional `choices`/`default`/`tool_call_id`/`tool_name`, `transcript` |
| Cancellation | `reason`, optional `operation_id`/`operation_type`, `partial_output_available` |
| Model/usage/cost | `provider`/`model`/`display`/`reasoning`, token/cost/context fields |
| Extension job failed | unwrapped handler `message`, `reason`, `handler_id`, optional `job_id`, `retry_count`, `attempts` |

Run/turn lifecycle events often have no standardized payload yet (mapper normalization).

## Compatibility

This is a published-ish wire surface for TUI and headless controller clients. Do not rename enum string values or drop required identity keys without an explicit task. Active development still follows root “no silent dual-format shims” rule — change call sites and tests together rather than adding permanent readers for old shapes unless the user requests a deprecation window.

## Maintenance

When adding/removing `RuntimeEventTypeEnum` cases or changing required payload keys, update mappers/translators/tests and this file’s invariants (not a full case dump) in the same change.
