---
builtin: true
description: Request-time conversation history conversion across providers and models.
---

# Conversation history conversion

Hatfield stores one canonical conversation history in `events.jsonl`. When a
session continues on a different model or provider, request construction converts
that history into the target transport shape. Canonical events are not rewritten.

Conversion runs through `AgentMessageConverter::toMessageBagForTarget()` at the
provider boundary after the current session model is resolved.
`ConversationHistoryConversion` remains a small request-time policy helper for
IDs, thinking, and native-item metadata. Each LLM worker keeps one active
`MessageBag` and the target model it was built for. Exact same-target contexts
reuse that bag. Appends convert only new messages and keep tool-call ID maps.
Open trailing tool-result batches are rebuilt only when an append continues
them, so closed turns skip that bookkeeping. Model changes, compaction, history
edits, and non-prefix contexts rebuild. Generic `toMessageBag()` callers always
receive a fresh bag and do not touch the active bag. Current request shapers
rebuild messages rather than mutating the supplied bag. `Image::fromFile`
closures reread bytes at serialization; the active bag rebuilds when an
`image_ref` path becomes unreadable or readable again. Canonical events stay
immutable and no shared database cache is used.

Source identity for conversion comes from `llm_step_completed.model` during
replay. Hatfield does not store a second `source_model` field on assistant
message payloads. Exact qualified model strings are compared (`provider/model`);
provider prefixes are not stripped.

## Supported transports

| Source history | Target transport | Tool-call IDs | Thinking / signatures | Notes |
|---|---|---|---|---|
| Generic chat completions (for example `zai/...`) | OpenAI Codex Responses | Normalize incompatible call IDs and matching result IDs; omit Responses item `id` | Visible thinking becomes assistant text; opaque signatures are dropped | Fixes the reported `call_...` item-id rejection |
| OpenAI Codex Responses | Generic chat completions | Composite `call_id\|fc_*` ids are remapped to bounded unique Completions ids; tool results follow the same map | Visible thinking becomes ordinary assistant text / `reasoning_content`; Codex encrypted signatures are not reusable | Reverse of the reported switch |
| Same provider/API, different model | Same transport | Associations preserved; Codex omits native item ids so discarded reasoning pairs are not required | Visible thinking becomes ordinary text; signatures are not reused | Same-provider model switch |
| Exact same qualified model | Same transport | Associations preserved; native Codex `fc_*` item ids may be replayed with matching `function_call_output.call_id` | Thinking signatures stay structured | Ordinary continuation / resume |
| Any source | Text-only target | Unchanged | Unchanged by this conversion | Unsupported images already become placeholders through `ImageGatingConvertHook` |

Grok uses the same generic chat-completions history shape as other non-Codex
providers for this matrix.

Cross-model IDs use alphanumeric characters, underscores, and hyphens, with a
40-character limit. Conversion resolves collisions within the request. Exact
same-model IDs remain unchanged. Provider names do not select conversion rules,
so custom provider aliases use the same behavior.

If source model identity is unavailable, conversion treats signatures as
nonportable. Existing completed-step events carry the model needed for replay.

## Unrepresentable content

These stay in stored history but cannot round-trip as native target metadata:

- Encrypted or opaque reasoning / thinking signatures across models or providers
- Codex Responses item ids that are not `fc_*`
- Redacted or empty opaque reasoning with no visible text
- Incomplete tool-call batches; `AgentMessageToolCallSequenceValidator` still rejects them before the provider call

## What stays unchanged

- Canonical `events.jsonl` content
- Message order, tool arguments, tool results, and call-result associations
- Existing sequence validation and image gating behavior
