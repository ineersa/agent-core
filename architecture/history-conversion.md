# Request-time history conversion

[Architecture](README.md) · [Context construction](context-and-projection.md)

Canonical `events.jsonl` never changes when switching providers/models.
`AgentMessageConverter::toMessageBagForTarget()` adapts current context at the
provider boundary after resolving the exact qualified target model.

| Target relationship | Conversion |
|---|---|
| Exact same qualified model | Preserve compatible call IDs and structured thinking/signatures; ChatGPT native call items may be reused |
| Different model/provider | Preserve message order, arguments and call/result associations; remap incompatible IDs; visible thinking becomes ordinary text; discard nonportable signatures/native metadata |
| Text-only model | Image gating substitutes unsupported image content separately |

Source model identity comes from replayed `llm_step_completed.model`, not a second
assistant-payload `source_model`. Provider aliases do not choose conversion policy;
exact `provider/model` identity does. Unknown source identity treats signatures as
nonportable. Cross-model call IDs use bounded collision-safe names; matching tool
results use the same mapping. Incomplete call/result batches remain invalid.

Each worker keeps one active target-aware `MessageBag`. Exact same-target prefix
appends convert only new messages; an append extending an open trailing tool-result
batch rebuilds that tail. Model switches, compaction, history edits or non-prefix
contexts rebuild the bag. Generic `toMessageBag()` callers get a fresh bag.
Request shapers do not mutate the supplied bag. Image-file readability changes can
force rebuilding because image closures read bytes at serialization. No shared DB
cache or alternate canonical history is introduced.

Sources: `AgentMessageConverter`, `ConversationHistoryConversion`,
`AgentMessageToolCallSequenceValidator` under
[src/AgentCore/Infrastructure/SymfonyAi](../src/AgentCore/Infrastructure/SymfonyAi).
