# Symfony AI Migration Plan

## Problem Statement

The current `Infrastructure/SymfonyAi/` namespace contains a custom implementation that **wraps** Symfony AI `PlatformInterface` through a thick bespoke layer that reimplements message mapping, stream delta reduction, and tool catalog resolution — all of which Symfony AI already provides natively and better.

The goal is to **delete this custom layer** and integrate Symfony AI Platform directly.

---

## Critical Decisions

### Cancellation Must Abort the HTTP Request

**Question**: Does wrapping `asStream()` in a generator that checks the cancellation token also cancel the underlying HTTP request?

**Answer**: No. Breaking out of `foreach ($deferredResult->asStream())` only stops consuming the generator — the HTTP connection stays open, burning tokens and memory until the provider finishes or the connection times out.

**Solution**: After breaking from the stream loop, call `$deferredResult->getRawResult()` (returns `RawHttpResult`) → `getObject()` (returns `ResponseInterface`) → `cancel()`. This immediately terminates the HTTP connection:

```php
private function consumeStream(DeferredResult $deferredResult, CancellationTokenInterface $cancelToken): PlatformInvocationResult
{
    $aborted = false;
    $deltas = [];

    try {
        foreach ($deferredResult->asStream() as $delta) {
            if ($cancelToken->isCancellationRequested()) {
                $aborted = true;
                break;
            }
            $deltas[] = $delta;  // keep Symfony AI delta types as-is
        }
    } catch (\Throwable $e) {
        return $this->errorResult($deltas, $e);
    }

    if ($aborted) {
        $this->abortConnection($deferredResult);
    }

    $usage = $this->extractUsage($deferredResult);
    return new PlatformInvocationResult(
        assistantMessage: $this->buildAssistantMessage($deltas),
        deltas: $deltas,
        usage: $usage,
        stopReason: $aborted ? 'aborted' : $this->resolveStopReason($deltas),
        error: null,
    );
}

private function abortConnection(DeferredResult $deferredResult): void
{
    try {
        $rawResult = $deferredResult->getRawResult();
        if ($rawResult instanceof RawHttpResult) {
            $rawResult->getObject()->cancel();
        }
    } catch (\Throwable) {
        // Connection already closed or not yet established — ignore
    }
}
```

### Use Symfony AI Toolbox for Tool Execution

Replace `ToolCatalogResolver` with `Symfony\AI\Agent\Toolbox\ToolboxInterface`. The current code already uses `FaultTolerantToolbox` inside `ToolExecutor` for execution — we just need to **also** use it for tool definition generation.

**Current split** (redundant):
- `ToolCatalogResolver` → generates OpenAI-format `['type' => 'function', 'function' => [...]]` arrays for the LLM request
- `ToolExecutor` → uses `ToolboxInterface::execute()` for actual execution

**After migration**:
- `ToolboxInterface::getTools()` → returns `Tool[]` objects (name, description, parameters)
- Symfony AI `Contract::createToolOption()` normalizes `Tool[]` to provider-specific format (OpenAI, Anthropic, etc.)
- `ToolboxInterface::execute()` → handles execution (already wired)
- `AgentProcessor::processInput()` injects tools into `$options['tools']` automatically

**What this means**: Delete `ToolCatalogResolver`, `ToolCatalogProviderInterface`, `ToolDefinition`, `ToolCatalogContext`. The Symfony AI Toolbox handles both discovery and execution.

**Toolbox events we get for free** (replaces `beforeToolCall` / `afterToolCall` hooks per `events_and_hooks_report.md`):

| Toolbox Event | Replaces |
|---|---|
| `ToolCallRequested` | `beforeToolCall` hook — supports deny + custom result short-circuit |
| `ToolCallSucceeded` | `afterToolCall` success hook |
| `ToolCallFailed` | `afterToolCall` error hook |
| `ToolCallsExecuted` | batch completion hook |
| `ToolCallArgumentsResolved` | argument validation hook |

**Dynamic tool descriptions per turn**: Use `ToolboxInterface::getTools()` and either:
- Override descriptions at the `AgentProcessor` level via custom `InputProcessorInterface`
- Or register tools as services with lazy description resolution

This needs a custom `InputProcessorInterface` that adjusts tool descriptions based on run context before each invocation.

### Use Symfony AI Stream Delta Types Directly

**Current**: `StreamDeltaReducer` accumulates deltas into raw arrays:
```php
['type' => 'text_delta', 'text' => 'Hello']
['type' => 'tool_call_start', 'id' => '...', 'name' => '...']
```

**After migration**: Keep Symfony AI delta types in `PlatformInvocationResult`:

```php
// PlatformInvocationResult uses DeltaInterface[] directly
final readonly class PlatformInvocationResult
{
    /**
     * @param list<DeltaInterface> $deltas
     * @param array<string, int|float> $usage
     * @param array<string, mixed>|null $error
     */
    public function __construct(
        public ?AssistantMessage $assistantMessage,  // Symfony AI type
        public array $deltas = [],                   // DeltaInterface[]
        public array $usage = [],
        public ?string $stopReason = null,
        public ?array $error = null,
    ) {}
}
```

Downstream code (`LlmStepResultHandler`, `RunEventPublisher`, etc.) works with typed deltas instead of raw arrays. The `assistantMessage` field becomes `Symfony\AI\Platform\Message\AssistantMessage` instead of `array`.

This means `LlmStepResult` also changes:
```php
final readonly class LlmStepResult extends AbstractAgentBusMessage
{
    public function __construct(
        string $runId,
        int $turnNo,
        string $stepId,
        int $attempt,
        string $idempotencyKey,
        public ?AssistantMessage $assistantMessage = null,  // typed
        public array $usage = [],
        public ?string $stopReason = null,
        public ?array $error = null,
    ) { parent::__construct($runId, $turnNo, $stepId, $attempt, $idempotencyKey); }
}
```

### Replace AgentMessage with Symfony AI Messages

**Current**: `AgentMessage` is a generic DTO (role string + content array + metadata). It's used throughout:
- `RunState::$messages` (hot store)
- `RunMessageStateTools::assistantMessage()` (constructs from platform result)
- `RunMessageStateTools::toolMessage()` (constructs from tool results)
- `CommandMailboxPolicy` (hydrates from payloads)
- `AgentRunner::steer()` / `followUp()` (public API)
- API controller (creates user messages)

**Symfony AI has**:
- `SystemMessage(string|Template)` — system instructions
- `UserMessage(ContentInterface...)` — user input (Text, Image, Audio)
- `AssistantMessage(?string, ?ToolCall[], ?thinkingContent, ?thinkingSignature)` — assistant response
- `ToolCallMessage(ToolCall, string)` — tool result

**Can we replace AgentMessage?** Almost — but `AgentMessage` has features Symfony AI messages don't:

| AgentMessage feature | Symfony AI equivalent | Gap |
|---|---|---|
| `role` as string | `MessageInterface::getRole()` enum | ✅ Covered (4 roles match) |
| `content` as `array<content-part>` | `UserMessage` has `ContentInterface[]`, `AssistantMessage` has `?string` | 🟡 `AssistantMessage.content` is a single string, not structured parts |
| `toolCallId` / `toolName` | `ToolCallMessage.toolCall` | ✅ Covered |
| `details` (arbitrary mixed) | No equivalent | ❌ `details` carries thinking, arguments, custom data |
| `isError` flag | No equivalent | ❌ Tool errors are represented differently |
| `metadata` array | `MetadataAwareTrait` | ✅ Covered |
| `name` field | No equivalent | 🟡 Used for named participants |
| Custom roles (`isCustomRole()`) | Only 4 roles in `Role` enum | ❌ Custom roles have no equivalent |

**Decision**: **Keep `AgentMessage` as the internal domain message type for now**, but create a one-way converter `AgentMessage → Symfony AI MessageBag`. The gaps (structured content parts on assistant messages, `details`, `isError`, custom roles) mean we can't do a 1:1 replacement without losing information.

**However**: For the *result* coming back from Symfony AI, we should use `AssistantMessage` directly (not convert to AgentMessage and back). The conversion only goes one way: `AgentMessage[]` (from RunState) → `Symfony AI MessageBag` (for the platform call).

### Events and Hooks — Align with `events_and_hooks_report.md`

Symfony AI already has event dispatching at two levels:

1. **Platform level**: `ModelRoutingEvent` (before provider routing), `InvocationEvent` (before provider invoke), `ResultEvent` (after invoke)
2. **Toolbox level**: `ToolCallRequested`, `ToolCallSucceeded`, `ToolCallFailed`, `ToolCallsExecuted`, `ToolCallArgumentsResolved`

**Mapping to events_and_hooks_report.md**:

| Report item | Current agent-core | Symfony AI equivalent | Migration |
|---|---|---|---|
| `before_provider_request` | `BeforeProviderRequestHookInterface` | `InvocationEvent` (mutable model/input/options) | Map hooks → `InvocationEvent` listeners |
| `transform_context` | `TransformContextHookInterface` | No equivalent (domain-specific) | Keep in adapter, pre-invoke |
| `convert_to_llm` | `ConvertToLlmHookInterface` | No equivalent (domain-specific) | Keep in adapter, pre-invoke |
| `model_select` | `ModelResolverInterface` | `ModelRoutingEvent` (can short-circuit to provider) | Map resolver → `ModelRoutingEvent` listener |
| `before_tool_call` | Indirect via Symfony Toolbox | `ToolCallRequested` (deny + custom result) | ✅ Use Toolbox events directly |
| `after_tool_call` | Indirect via Symfony Toolbox | `ToolCallSucceeded` / `ToolCallFailed` | ✅ Use Toolbox events directly |
| `agent_start` | Contract only, no emission | No equivalent | Keep domain event emission |
| `agent_end` | Emitted in handlers | No equivalent | Keep domain event emission |
| `turn_start` / `turn_end` | `turn_advanced` event | No equivalent | Keep domain event emission |
| `message_start` / `message_update` / `message_end` | Partial | No equivalent | Keep domain event emission |

**New approach**: 
- Domain lifecycle events (turn, message, agent) remain domain concerns. Symfony AI doesn't know about turns.
- Provider-level hooks (`before_provider_request`, `model_select`) map to Symfony AI `InvocationEvent` / `ModelRoutingEvent` listeners registered on the platform's `EventDispatcher`.
- Tool hooks map to Symfony AI Toolbox events.
- Domain-specific hooks (`transform_context`, `convert_to_llm`) stay in the adapter.

**Update `events_and_hooks_report.md`** after migration to reflect:
- `before_provider_request` → now implemented via `InvocationEvent` listener
- `model_select` → now implemented via `ModelRoutingEvent` listener
- `before_tool_call` → now `ToolCallRequested` event
- `after_tool_call` → now `ToolCallSucceeded` / `ToolCallFailed` events

---

## Files to Delete

| File | Reason |
|---|---|
| `Infrastructure/SymfonyAi/Platform.php` | Replaced by `LlmPlatformAdapter` |
| `Infrastructure/SymfonyAi/SymfonyPlatformInvoker.php` | Absorbed into adapter |
| `Infrastructure/SymfonyAi/SymfonyMessageMapper.php` | Replaced by `AgentMessageConverter` |
| `Infrastructure/SymfonyAi/StreamDeltaReducer.php` | Replaced by direct `instanceof` on Symfony AI deltas |
| `Application/Handler/ToolCatalogResolver.php` | Replaced by `ToolboxInterface::getTools()` + `Contract::createToolOption()` |
| `Contract/Tool/ToolCatalogProviderInterface.php` | No longer needed |
| `Domain/Tool/ToolDefinition.php` | Replaced by `Symfony\AI\Platform\Tool\Tool` |
| `Domain/Tool/ToolCatalogContext.php` | Replaced by toolbox + context in InputProcessor |

## Files to Create

| File | Purpose |
|---|---|
| `Infrastructure/SymfonyAi/LlmPlatformAdapter.php` | Thin adapter: hooks + invoke + stream + cancel |
| `Infrastructure/SymfonyAi/AgentMessageConverter.php` | One-way `AgentMessage[]` → Symfony AI `MessageBag` |
| `Infrastructure/SymfonyAi/DynamicToolDescriptionProcessor.php` | `InputProcessorInterface` for per-turn tool description injection |

## Files to Keep

| File | Reason |
|---|---|
| `RunCancellationToken.php` | Custom cancellation tied to `RunStore` |
| `Contract/Tool/PlatformInterface.php` | Stable contract — adapter implements it |
| `Domain/Tool/PlatformInvocationResult.php` | Internal DTO — change `assistantMessage` to `?AssistantMessage`, `deltas` to `DeltaInterface[]` |
| `Domain/Tool/ModelInvocationRequest.php` | Internal DTO — unchanged |
| `AgentMessage.php` | Domain message type — too deeply integrated to replace now |
| All hook interfaces | Keep `TransformContextHookInterface`, `ConvertToLlmHookInterface`. Delete `BeforeProviderRequestHookInterface` (→ `InvocationEvent`). Keep `SteeringMessagesProviderInterface`, `FollowUpMessagesProviderInterface`. |
| `ToolExecutor.php` | Already uses `FaultTolerantToolbox` — stays |
| `FakePlatform` (test) | Test infrastructure |

## Files to Modify

| File | Change |
|---|---|
| `Domain/Tool/PlatformInvocationResult.php` | `$assistantMessage` → `?AssistantMessage`, `$deltas` → `list<DeltaInterface>` |
| `Domain/Message/LlmStepResult.php` | `$assistantMessage` → `?AssistantMessage` |
| `Application/Handler/ExecuteLlmStepWorker.php` | Adapt to typed `$response->assistantMessage` |
| `Application/Orchestrator/RunMessageStateTools.php` | `assistantMessage()` accepts `AssistantMessage` |
| `Application/Orchestrator/LlmStepResultHandler.php` | Work with `DeltaInterface[]` and typed `AssistantMessage` |
| `Configuration.php` | Add `platform`, `api_key`, `base_url` to `llm` node |
| `AgentLoopExtension.php` | Build platform from config |
| `config/services.php` | Wire new services, remove old |
| `events_and_hooks_report.md` | Update mapping |

---

## Architecture After Migration

```
┌───────────────────────────────────────────────────────────────┐
│  ExecuteLlmStepWorker                                         │
│                                                               │
│  Contract\Tool\PlatformInterface                              │
│    └─► LlmPlatformAdapter (thin)                              │
│          ├─ applies transformContext hooks (domain)           │
│          ├─ applies convertToLlm hooks / default converter    │
│          ├─ resolves model via ModelRoutingEvent              │
│          ├─ invokes Symfony\AI\Platform\PlatformInterface     │
│          ├─ consumes asStream() with cancellation + abort     │
│          └─ returns PlatformInvocationResult                  │
│              (AssistantMessage, DeltaInterface[], usage)       │
│                                                               │
│  Symfony\AI\Platform\Platform                                 │
│    ├─ ModelRoutingEvent → model selection                     │
│    ├─ InvocationEvent → before_provider_request hooks         │
│    ├─ Provider → ModelClient + ResultConverter                │
│    └─ ResultEvent → post-invoke                               │
│                                                               │
│  Tool Execution (Toolbox)                                     │
│    ├─ ToolboxInterface::getTools() → Tool[] → Contract        │
│    ├─ ToolboxInterface::execute(ToolCall) → ToolResult        │
│    ├─ ToolCallRequested → deny / short-circuit                │
│    ├─ ToolCallSucceeded / ToolCallFailed → after hooks        │
│    └─ DynamicToolDescriptionProcessor (per-turn descriptions) │
└───────────────────────────────────────────────────────────────┘
```

---

## Detailed Migration Steps

### Phase 1: Bundle Configuration — Platform + Model from Config

**`Configuration.php`** — extend `llm` node:
```yaml
agent_loop:
    llm:
        default_model: 'gpt-4o-mini'
        platform: 'openai'                    # openai|anthropic|generic|ollama|custom
        api_key: '%env(OPENAI_API_KEY)%'
        base_url: null                        # for generic/self-hosted
```

**`AgentLoopExtension.php`** — build `Symfony\AI\Platform\PlatformInterface`:
```php
match ($config['llm']['platform']) {
    'openai' => OpenAi\Factory::createPlatform($apiKey),
    'anthropic' => Anthropic\Factory::createPlatform($apiKey),
    'generic' => Generic\Factory::createPlatform($baseUrl, $apiKey),
    'ollama' => Ollama\Factory::createPlatform($baseUrl),
    'custom' => null, // user wires their own service
};
```

### Phase 2: Change PlatformInvocationResult + LlmStepResult to Use Symfony AI Types

```php
// PlatformInvocationResult
public function __construct(
    public ?AssistantMessage $assistantMessage,   // was array
    public array $deltas = [],                    // list<DeltaInterface>, was list<array>
    public array $usage = [],                     // unchanged
    public ?string $stopReason = null,            // unchanged
    public ?array $error = null,                  // unchanged
) {}

// LlmStepResult
public function __construct(
    string $runId, int $turnNo, string $stepId, int $attempt, string $idempotencyKey,
    public ?AssistantMessage $assistantMessage = null,  // was array
    public array $usage = [],
    public ?string $stopReason = null,
    public ?array $error = null,
) { parent::__construct(...); }
```

This cascades changes to `ExecuteLlmStepWorker`, `RunMessageStateTools`, `LlmStepResultHandler`, serialization, etc.

### Phase 3: Create AgentMessageConverter

One-way converter from domain `AgentMessage[]` → Symfony AI `MessageBag`:

```php
final class AgentMessageConverter
{
    public function toMessageBag(array $agentMessages): SymfonyMessageBag
    {
        $messages = [];
        foreach ($agentMessages as $msg) {
            $messages[] = match ($msg->role) {
                'system' => Message::forSystem($this->contentToText($msg->content)),
                'assistant' => Message::ofAssistant(
                    $this->contentToText($msg->content) ?: null,
                    $this->extractToolCalls($msg),
                ),
                'tool' => $this->toToolCallMessage($msg),
                default => Message::ofUser($this->contentToText($msg->content)),
            };
        }
        return new SymfonyMessageBag(...$messages);
    }
}
```

### Phase 4: Create LlmPlatformAdapter

Thin adapter that:
1. Resolves run context + cancellation token (moved from current `Platform.php`)
2. Applies `transformContext` hooks
3. Applies `convertToLlm` hooks (or falls back to `AgentMessageConverter`)
4. Invokes `$this->platform->invoke($model, $messageBag, $options)` 
5. Consumes `asStream()` with cancellation + HTTP abort
6. Returns `PlatformInvocationResult` with typed deltas + `AssistantMessage`

`beforeProviderRequest` hooks → registered as `InvocationEvent` listeners on the platform's `EventDispatcher`.

`ModelResolverInterface` → registered as `ModelRoutingEvent` listener.

### Phase 5: Stream Consumption with HTTP Abort

```php
private function consumeStream(DeferredResult $deferredResult, CancellationTokenInterface $cancelToken): PlatformInvocationResult
{
    $deltas = [];
    $aborted = false;

    try {
        foreach ($deferredResult->asStream() as $delta) {
            if ($cancelToken->isCancellationRequested()) {
                $aborted = true;
                break;
            }
            $deltas[] = $delta;   // keep as DeltaInterface — no conversion
        }
    } catch (\Throwable $e) {
        return $this->errorResult($deltas, $e, $deferredResult);
    }

    if ($aborted) {
        $this->abortConnection($deferredResult);
    }

    $usage = $this->extractUsage($deferredResult);
    $assistantMessage = $this->buildAssistantMessage($deltas);

    return new PlatformInvocationResult(
        assistantMessage: $assistantMessage,
        deltas: $deltas,
        usage: $usage,
        stopReason: $aborted ? 'aborted' : ($assistantMessage?->hasToolCalls() ? 'tool_call' : null),
        error: null,
    );
}

private function abortConnection(DeferredResult $deferredResult): void
{
    try {
        $raw = $deferredResult->getRawResult();
        if ($raw instanceof RawHttpResult) {
            $raw->getObject()->cancel();   // kills the HTTP connection
        }
    } catch (\Throwable) { /* already closed */ }
}
```

**Building AssistantMessage from deltas** — iterate `DeltaInterface[]` and reconstruct:
```php
private function buildAssistantMessage(array $deltas): ?AssistantMessage
{
    $text = '';
    $thinking = null;
    $thinkingSignature = null;
    $toolCalls = null;

    foreach ($deltas as $delta) {
        match (true) {
            $delta instanceof TextDelta => $text .= $delta->getText(),
            $delta instanceof ThinkingComplete => $thinking = $delta->getThinking() ?? $thinking,
            $delta instanceof ToolCallComplete => $toolCalls = $delta->getToolCalls(),
            default => null,
        };
    }

    if ('' === $text && null === $toolCalls) {
        return null;
    }

    return new AssistantMessage(
        content: '' !== $text ? $text : null,
        toolCalls: $toolCalls,
        thinkingContent: $thinking,
        thinkingSignature: $thinkingSignature,
    );
}
```

### Phase 6: Usage Extraction via Symfony AI Metadata

```php
private function extractUsage(DeferredResult $deferredResult): array
{
    $tokenUsage = $deferredResult->getMetadata()->get('token_usage');
    if (!$tokenUsage instanceof TokenUsageInterface) {
        return [];
    }
    return array_filter([
        'input_tokens' => $tokenUsage->getPromptTokens(),
        'output_tokens' => $tokenUsage->getCompletionTokens(),
        'thinking_tokens' => $tokenUsage->getThinkingTokens(),
        'tool_tokens' => $tokenUsage->getToolTokens(),
        'cached_tokens' => $tokenUsage->getCachedTokens(),
        'total_tokens' => $tokenUsage->getTotalTokens(),
    ], static fn ($v) => null !== $v);
}
```

### Phase 7: Replace ToolCatalogResolver with Toolbox

Delete `ToolCatalogResolver`, `ToolCatalogProviderInterface`, `ToolDefinition`, `ToolCatalogContext`.

Tool definitions come from `ToolboxInterface::getTools()` → Symfony AI `Contract::createToolOption()` normalizes to provider-specific format.

For **dynamic per-turn descriptions**, create `DynamicToolDescriptionProcessor`:
```php
final class DynamicToolDescriptionProcessor implements InputProcessorInterface
{
    public function processInput(Input $input): void
    {
        // Adjust tool descriptions based on run context before invoke
        $options = $input->getOptions();
        $tools = $options['tools'] ?? [];
        // ... modify descriptions based on context ...
        $options['tools'] = $tools;
        $input->setOptions($options);
    }
}
```

### Phase 8: Wire Hooks as Symfony AI Event Listeners

**`beforeProviderRequest` hooks** → `InvocationEvent` listeners:
```php
// In services.php — register a listener that iterates hooks
$eventDispatcher->addListener(InvocationEvent::class, function (InvocationEvent $event) use ($hooks) {
    foreach ($hooks as $hook) {
        $request = $hook->beforeProviderRequest(
            $event->getModel()->getName(),
            // ... adapt input/options from event
        );
        if ($request) {
            $event->setModel(new Model($request->model));
            $event->setInput($request->input);
            $event->setOptions($request->options);
        }
    }
});
```

**`ModelResolverInterface`** → `ModelRoutingEvent` listener:
```php
$eventDispatcher->addListener(ModelRoutingEvent::class, function (ModelRoutingEvent $event) use ($resolver) {
    $resolved = $resolver->resolve($event->getModel(), ...);
    $event->setModel($resolved->model);  // or route to specific provider
});
```

**Tool hooks** → already covered by Toolbox events (`ToolCallRequested`, etc.)

### Phase 9: Keep RunCancellationToken

Stays unchanged. Used by both `LlmPlatformAdapter` (via stream abort) and `ExecuteToolCallWorker` (pre-execution check).

### Phase 10: Update Downstream Consumers

Files that consume `PlatformInvocationResult::$assistantMessage` (was `array`, now `?AssistantMessage`):

- **`RunMessageStateTools::assistantMessage()`** — change parameter from `array` to `?AssistantMessage`
- **`LlmStepResultHandler`** — extract tool calls from `AssistantMessage::getToolCalls()` instead of `$msg['tool_calls']`
- **Serialization** — `CommandPayloadNormalizer::normalizeLlmStepResult()` must normalize `AssistantMessage` via Symfony Serializer
- **Event payloads** — `tool_execution_start` event needs tool calls from typed source

---

## Execution Order

1. **Phase 1** — Bundle config (platform/model/api_key from config) — standalone
2. **Phase 2** — Change `PlatformInvocationResult` + `LlmStepResult` types — cascading
3. **Phase 3** — Create `AgentMessageConverter` — standalone
4. **Phase 4** — Create `LlmPlatformAdapter` — standalone
5. **Phase 5 + 6** — Stream consumption + usage + HTTP abort
6. **Phase 7** — Replace `ToolCatalogResolver` with Toolbox — delete old files
7. **Phase 8** — Wire hooks as event listeners
8. **Phase 10** — Update downstream consumers (handlers, serializers, events)
9. **Wire** — Update `config/services.php`
10. **Update** `events_and_hooks_report.md`
11. **Delete** old files (`Platform.php`, `SymfonyPlatformInvoker.php`, `SymfonyMessageMapper.php`, `StreamDeltaReducer.php`)
12. **Run** `castor dev:check`

---

## Config Example (Final State)

```yaml
agent_loop:
    llm:
        default_model: 'gpt-4o-mini'
        platform: 'openai'                    # openai | anthropic | generic | ollama
        api_key: '%env(OPENAI_API_KEY)%'
```

App developer sets `platform` (which provider) + `default_model`. Everything else flows through Symfony AI. Multi-provider setups use Symfony AI bundle's own configuration.

---

## Summary of What Changes for Downstream Code

**Before** (raw arrays everywhere):
```php
$response->assistantMessage['content'][0]['text']
$response->assistantMessage['tool_calls'][0]['name']
$response->deltas()[0]['type'] === 'text_delta'
```

**After** (typed):
```php
$response->assistantMessage->getContent()
$response->assistantMessage->getToolCalls()[0]->getName()
$response->deltas[0] instanceof TextDelta
```
