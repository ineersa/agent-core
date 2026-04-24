# Arrays → DTO/ValueObject Replacement Plan

## Why

Current code relies on many shaped arrays (`array{...}`), tuple arrays (`array{0: ..., 1: ...}`), and untyped maps (`array<string, mixed>`) across service contracts.

This plan replaces structural arrays with explicit DTOs/ValueObjects, grouped by namespace, with a phased migration path.

---

## Rules for replacement

1. **Replace fixed-shape associative arrays** with `readonly` DTO/VO classes.
2. **Replace tuple arrays** (`array{0: ..., 1: ...}`) with named result DTOs.
3. **Do not introduce pass-through wrappers** (`public array $values`, `all()` only) for deterministic schemas. If we know keys, model them as typed properties (and nested objects where appropriate).
4. **Keep truly polymorphic maps** (extension payloads, unknown provider metadata) as `array<string, mixed>` for now, but encapsulate behind typed wrappers with meaningful typed fields around the map.
5. Use Symfony Serializer (`denormalize()` / `normalize()`) at boundaries to map transport payloads directly to typed DTOs/VOs.
6. Do not add DTO/VO `fromArray()` / `toArray()` helpers; boundary conversion belongs to Symfony Serializer.

### Refactor review guardrails (added 2026-04-23)

- Public application services (`Application\Handler\*`) must not return shaped arrays when a DTO already exists for the same concept.
- For known option bags (example: `array{cancel_safe?: bool}`), define typed option objects instead of `array<string, mixed>` pass-through storage.
- Artifact metadata must expose typed fields (`mediaType`, `encoding`, etc.) and optional extension attributes, not a raw `values` bag.
- PR review checklist for this stream:
  1. Any new `array{...}` in public signatures? If yes, justify polymorphism or replace with DTO.
  2. Any new DTO with only `array $values` + `all()`? Reject and redesign.
  3. Any boundary still converting DTO → array manually in core services? Move conversion to transport boundary.

---

## Progress snapshot (2026-04-23)

### Completed

- [x] Removed compatibility-track strategy from this plan (no adapters/shims, no `V2` track).
- [x] Introduced and wired initial DTO/VO set for contract boundaries:
  - `ModelInvocationRequest`
  - `ModelResolutionContext`
  - `ModelResolutionOptions`
  - `ToolCatalogContext`
  - `PromptState`
  - `ArtifactMetadata` (typed fields + extension attributes)
  - `AfterTurnCommitHookContext`
  - `AfterTurnCommitEventSummary`
  - `CommandCancellationOptions`
- [x] Removed `CommandMappingPayload` pass-through wrapper; extension command payload remains explicit `array<string, mixed>` at polymorphic boundary.
- [x] Updated primary contracts and core call sites to consume typed objects instead of structural arrays.
- [x] Removed array return shapes in replay/debug hot-state path:
  - `ReplayService::rebuildHotPromptState()` now returns `PromptState`
  - `ReplayService::verifyIntegrity()` now returns `Application\Dto\ReplayIntegrity`
  - `RunDebugService::inspect()` now returns `Application\Dto\RunDebugSnapshot`

### In progress

- [ ] Phase 1 response-shape cleanup in Application/Api (`LatencyHistogramSnapshot`, `ReplayIntegrity`, `RunSummaryResponse` and downstream callers).

### Next

- [ ] Phase 2 schema envelope cut (`EventEnvelope` + command/event envelope DTOs).
- [ ] Phase 3 infrastructure/provider deep typing (`TokenUsage`, `StreamDelta`, `ResolvedProviderRequest`, `RunContext`).
- [ ] Phase 5 domain payload deepening (`AgentMessage` content parts, set/map wrappers such as `PendingToolCallSet`).

## Namespace plan

## `Ineersa\AgentCore\Api`

### Replace

- `Api\Http\RunReadService::summary()`
  - Current: `?array{run_id, status, turn_count, created_at, updated_at, latest_summary, waiting_flags: array{...}}`
  - Replace with:
    - `Api\Dto\RunSummaryResponse`
    - `Api\Dto\RunWaitingFlags`

- `Api\Http\RunReadService::transcriptPage()`
  - Current: `?array{run_id, cursor, next_cursor, has_more, total, items: list<array{...}>}`
  - Replace with:
    - `Api\Dto\TranscriptPageResponse`
    - `Api\Dto\TranscriptItemResponse`
    - (optional) `Api\Dto\SerializedAgentMessage`

- `Api\Http\RunReadService::replayAfter()`
  - Current: `?array{run_id, source, resync_required, missing_sequences, events}`
  - Replace with:
    - `Api\Dto\ReplayAfterResponse`

- `Api\Serializer\RunEventSerializer::{normalizeRunEvent, normalizeStreamEvent}`
  - Current: `array<string, mixed>`
  - Replace with:
    - `Schema\Dto\EventEnvelope` (shared envelope type)

### Notes

- `latestSummary()` itself can stay private logic; the cleanup is the **return contract** of callers.
- Controllers/commands should serialize DTOs, not build arrays inline.

---

## `Ineersa\AgentCore\Application`

### Replace

- `Application\Handler\ReplayService`
  - `rebuildHotPromptState()` → `Domain\Run\PromptState`
  - `verifyIntegrity()` → `Application\Dto\ReplayIntegrity`
  - `eventsForReplay()` tuple → `Application\Dto\ResolvedReplayEvents`

- `Application\Handler\RunDebugService`
  - `inspect()` → `Application\Dto\RunDebugSnapshot`
    - composed from:
      - `RunStateSnapshot`
      - `ReplayIntegrity`
      - `HotPromptStateSnapshot`
      - `PendingCommandSnapshot`
      - `MetricsSnapshot`
  - `replayAfter()` → `Application\Dto\DebugReplayAfter`
  - `tail()` → `Application\Dto\RunTail`
  - `normalizeHotPromptState()` → `HotPromptStateSnapshot`

- `Application\Handler\LatencyHistogram::snapshot()`
  - Current shape array
  - Replace with `Application\Dto\LatencyHistogramSnapshot`

- `Application\Handler\RunMetrics::snapshot()`
  - Current nested array tree
  - Replace with `Application\Dto\MetricsSnapshot` (+ nested DTOs)

- `Application\Orchestrator\CommandMailboxPolicy`
  - `applyPendingTurnStartCommands()` tuple → `TurnStartCommandApplyResult`
  - `applyPendingStopBoundaryCommands()` tuple → `StopBoundaryCommandApplyResult`
  - event specs `list<array{type,payload}>` → `EventSpec` DTO

- `Application\Orchestrator\RunMessageStateTools`
  - `eventsFromSpecs(...)` input list arrays → `list<EventSpec>`
  - `extractToolCalls()` return arrays → `list<ExtractedToolCall>`
  - `interruptPayloadFromToolResult()` array/null → `?ToolInterruptPayload`

- `Application\Handler\ToolBatchCollector`
  - Internal `$batches` shape array → `ToolBatchState` object

- `Application\Orchestrator\LlmStepResultHandler::resolveToolPolicy()`
  - Current: array `{mode, timeout_seconds, max_parallelism}`
  - Replace with existing `Domain\Tool\ToolExecutionPolicy` (or a dedicated VO if needed)

### Notes

- This namespace has the largest volume of tuple arrays and nested response arrays.
- Refactor here should be incremental and test-led, starting with histogram/metrics and replay integrity DTOs.

---

## `Ineersa\AgentCore\Infrastructure`

### Replace

- `Infrastructure\SymfonyAi\Platform`
  - `runContextFrom()` map → `Infrastructure\SymfonyAi\Dto\RunContext`
  - `applyBeforeProviderRequestHooks()` tuple → `Domain\Tool\ResolvedProviderRequest`
  - `providerInputFrom()` `array|object` → `ProviderInput` wrapper type

- `Infrastructure\SymfonyAi\SymfonyMessageMapper`
  - `toGenericMessageObject()` array→object cast → explicit DTO (`GenericMessagePayload`)
  - `normalizeProviderMessage()` map → `NormalizedProviderMessage`
  - `toProviderInput()` `array|object` → `ProviderInput` wrapper

- `Infrastructure\SymfonyAi\StreamDeltaReducer`
  - `$deltas` `list<array<string,mixed>>` → `list<StreamDelta>` (sealed/discriminated hierarchy)
  - `$usage` map → `Domain\Tool\TokenUsage`
  - `assistantMessage()` map → `AssistantMessagePayload`
  - `orderedToolCalls()` list arrays → `list<ToolCallDescriptor>`

- `Infrastructure\SymfonyAi\SymfonyPlatformInvoker`
  - `extractUsage()` map → `TokenUsage`
  - `metadataFrom()` `array|object|null` → `PlatformMetadata` wrapper

### Notes

- `StreamDeltaReducer` is a high-value target: replacing `type` string switches with typed delta variants removes fragile key-based access.

---

## `Ineersa\AgentCore\Schema`

### Replace

- `Schema\CommandPayloadNormalizer`
  - all `normalize*()` methods currently return `array<string,mixed>` envelopes
  - replace with typed envelopes:
    - `StartRunEnvelope`
    - `ApplyCommandEnvelope` (with discriminated variants)
    - `ExecuteLlmStepEnvelope`
    - `LlmStepResultEnvelope`
    - `ExecuteToolCallEnvelope`
    - `ToolCallResultEnvelope`

- `Schema\EventPayloadNormalizer`
  - `normalize*()` returns map envelope → `EventEnvelope`
  - `denormalizeRunEvent()` should denormalize into `EventEnvelope` first, then `RunEvent`

### Notes

- This is a cross-boundary serialization namespace; switch each boundary in one cut and update its consumers in the same phase.

---

## `Ineersa\AgentCore\Contract`

### Replace

- `Contract\Tool\PlatformInterface::invoke(...)`
  - Current: input/options arrays + array return
  - Replace with:
    - `ModelInvocationRequest`
    - `ModelInvocationResult`

- `Contract\Tool\ModelResolverInterface::resolve(...)`
  - context/options arrays → `ModelResolutionContext` + `ModelResolutionOptions`

- `Contract\Tool\ToolCatalogProviderInterface::resolveToolCatalog(...)`
  - context array → `ToolCatalogContext`

- `Contract\PromptStateStoreInterface`
  - `get()/save()` array state → `PromptState`

- `Contract\ArtifactStoreInterface::put(...)`
  - metadata array → `ArtifactMetadata`

- `Contract\Extension\HookSubscriberInterface::handleAfterTurnCommit(...)`
  - `AFTER_TURN_COMMIT` context array → `AfterTurnCommitHookContext`

- `Contract\Extension\CommandHandlerInterface::map(...)`
  - payload array remains explicit polymorphic map (`array<string, mixed>`)
  - options array shape `array{cancel_safe?: bool}` → `CommandCancellationOptions`

### Notes

- Contract changes are intentional breaking changes in this stream.
- Replace existing interfaces in place; do not introduce parallel `V2` interfaces.

---

## `Ineersa\AgentCore\Domain`

### Replace

- `Domain\Tool\ProviderRequest::applyOn()`
  - tuple-like associative result → `ResolvedProviderRequest`

- `Domain\Tool\ToolDefinition::toProviderPayload()`
  - array payload → `ProviderToolDefinition` (+ nested `ProviderFunctionDefinition`)

- `Domain\Tool\PlatformInvocationResult`
  - array properties (`assistantMessage`, `deltas`, `usage`, `error`) → typed VOs:
    - `AssistantMessagePayload`
    - `list<StreamDelta>`
    - `TokenUsage`
    - `ProviderError`

- `Domain\Message\AgentMessage`
  - `content: list<array<string,mixed>>` → `list<MessageContentPart>`
  - `metadata: array<string,mixed>` → `MessageMetadata` (can remain map-backed internally)

- `Domain\Run\RunState`
  - `pendingToolCalls: array<string,bool>` → `PendingToolCallSet`

### Keep for now (encapsulate, do not explode yet)

- `RunEvent::$payload` and extension payload maps (`array<string,mixed>`) remain polymorphic by design.
- Wrap access with typed helpers where event kind is known.

---

## `Ineersa\AgentCore\Command` (integration impact)

When `Application` service returns switch to DTOs, update:

- `AgentLoopRunInspectCommand`
- `AgentLoopRunReplayCommand`
- `AgentLoopRunTailCommand`
- `AgentLoopRunRebuildHotStateCommand`

to consume typed DTOs instead of nested array keys.

---

## Execution order (phased)

### Phase 1 — Low-risk, high-value internals

1. `LatencyHistogramSnapshot` DTO
2. `ReplayIntegrity` DTO
3. `ResolvedReplayEvents` tuple replacement
4. `RunSummaryResponse` + `RunWaitingFlags`

### Phase 2 — Serialization envelopes

1. `EventEnvelope` in `Schema`
2. Command envelope DTO set in `Schema\CommandPayloadNormalizer`
3. `RunEventSerializer` to return envelope DTOs directly

### Phase 3 — Infrastructure/provider contracts

1. `TokenUsage` VO shared by reducer/invoker/result
2. `StreamDelta` typed hierarchy
3. `ResolvedProviderRequest` and `RunContext` VOs

### Phase 4 — Contract hardening (breaking cut)

1. Replace `PlatformInterface::invoke(...)` directly with request/result DTOs
2. Replace context/options arrays in model/tool catalog contracts with typed VOs
3. Move prompt/artifact/hook contracts to typed payload objects in place (no `V2` track)

### Phase 5 — Domain payload deepening

1. `AgentMessage` content parts VO hierarchy
2. `PlatformInvocationResult` full typed fields
3. `PendingToolCallSet` and other domain set/map wrappers

---

## Migration mechanics

- Add DTO/VOs with:
  - `readonly` properties
  - strict constructor invariants
  - Symfony Serializer metadata where needed
- Migrate call sites namespace-by-namespace.
- No adapters, no compatibility shims, no parallel `V2` interface layer.
- Do not implement DTO/VO `fromArray()` / `toArray()` compatibility methods.
- Apply contract changes as a deliberate breaking cut.
- Remove array-key access immediately as each namespace is migrated.

---

## Definition of done for this refactor stream

- No shaped-array return types in targeted public services/contracts.
- No tuple-array return types in orchestrator/service APIs.
- Command/event normalization uses typed envelope DTOs.
- Remaining `array<string,mixed>` are explicitly marked polymorphic and encapsulated.
