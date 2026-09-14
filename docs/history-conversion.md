---
builtin: true
description: Request-time conversation history conversion across providers and models.
---

# Conversation history conversion

Hatfield stores one canonical conversation history in `events.jsonl`. When a
session continues on a different model or provider, request construction converts
that history into the target transport shape. Canonical events are not rewritten.

## Supported transports

| Source history | Target transport | Tool-call IDs | Thinking / signatures | Notes |
|---|---|---|---|---|
| Generic chat completions (for example `zai/...`) | OpenAI Codex Responses | Keep stored call id on `call_id`; omit Responses item `id` unless it is a native `fc_*` item id | Visible thinking becomes assistant text; opaque signatures are dropped | Fixes the reported `call_...` item-id rejection |
| OpenAI Codex Responses | Generic chat completions | Stored call id is reused as the chat-completions tool id | Visible thinking becomes `reasoning_content` when present; Codex encrypted signatures are not reusable | Reverse of the reported switch |
| Same provider/API, different model | Same transport | Associations preserved; Codex omits native item ids so discarded reasoning pairs are not required | Visible thinking becomes ordinary text; signatures are not reused | Same-provider model switch |
| Exact same qualified model | Same transport | Associations preserved; native Codex `fc_*` item ids may be replayed | Thinking signatures stay structured | Ordinary continuation / resume |
| Any source | Text-only target | Unchanged | Unchanged by this conversion | Unsupported images already become placeholders through `ImageGatingConvertHook` |

Grok uses the same generic chat-completions history shape as other non-Codex
providers for this matrix.

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
