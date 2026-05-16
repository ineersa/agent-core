# Symfony AI Platform Integration Plan

## Status

Planning draft created 2026-05-16.

Updated against `task/2026-05-16-upgrade-symfony-ai-packages-to-0-9` / PR #6, which migrates AgentCore to Symfony AI 0.9 and the new `AssistantMessage` content-part API.

This plan covers wiring Symfony AI Platform into Hatfield/AgentCore after the separate Symfony AI `0.9` upgrade task lands. The goal is to make the existing `LlmPlatformAdapter` talk to configured real providers, persist model/reasoning selection, and allow per-turn model changes.

## Symfony AI 0.9 migration impact

The 0.9 branch does not change the provider/settings architecture in this plan, but it changes the message API assumptions implementations must use:

- `AssistantMessage` is now constructed from variadic content parts (`Text`, `Thinking`, `ToolCall`, etc.), not named `content`, `toolCalls`, `thinkingContent`, `thinkingSignature` arguments.
- Reading assistant text should use `AssistantMessage::asText()`.
- Reading tool calls should use `AssistantMessage::getToolCalls()`.
- Reading thinking should use `AssistantMessage::hasThinking()` / `getThinking()` returning `Content\Thinking` parts.
- Existing 0.9 migration reviewed new delta types (`BinaryDelta`, `ChoiceDelta`, `MetadataDelta`, `ThinkingStart`) and intentionally leaves them ignored by `LlmPlatformAdapter` unless we add product behavior for them.

Implications for this platform work:

- Any tests/fakes for configured providers must build 0.9-style `AssistantMessage` objects, e.g. `new AssistantMessage(new Text('...'))`.
- Reasoning-level selection is an invocation-option concern. Do not conflate it with returned `Content\Thinking` blocks; those are response content extracted after provider invocation.
- Provider integrations should pass model/reasoning through `ModelRoutingEvent` options and let the existing Symfony AI 0.9 adapter/converter handle response content.

## Scope decisions

### In scope for first implementation

- Configure and instantiate Symfony AI Platform providers from Hatfield settings.
- Treat Hatfield's own rich model catalog as the source of truth; Symfony model catalogs are only adapter projections for provider routing/capability checks.
- Support OpenAI chat-completions-style providers first, including DeepSeek, z.ai, and llama.cpp, via Symfony provider adapters.
- Do not rely on Symfony's built-in provider catalogs for model facts. Current bridge catalogs are too thin and may be stale/incorrect for our use case.
- Persist selected model and reasoning level in:
  - global/home Hatfield settings as user defaults,
  - session metadata as current session state.
- Pass current model and reasoning level into AgentCore for every LLM turn.
- Keep model IDs user-facing as `provider_id/model_name`, while passing raw `model_name` to Symfony AI.

### Out of scope for first implementation

- Codex bridge integration. Symfony's Codex bridge shells out to the local `codex` CLI and is not the proper Codex provider support we expected. Build first-party/proper Codex support later.
- OpenAI/Anthropic/Ollama first-class provider setup. They can be added after the DeepSeek/z.ai/llama.cpp chat-completions path is working.
- Fancy TUI model picker. Start with settings + internal services; add `/model` and picker later.
- Symfony AI `0.9` upgrade itself. That is tracked separately and should land first.

## Desired settings shape

Hatfield settings should grow an `ai` section. Home settings are the right place for personal defaults and secrets references. Project settings can override shared project-local providers/models and may also override `ai.default_model`; this follows existing Hatfield precedence rules.

On launch, if `~/.hatfield/settings.yaml` does not exist, create it by copying the documented default/example settings. When writing model, reasoning, or favorite changes back to home settings, preserve comments so the file remains user-readable documentation.

```yaml
ai:
    default_model: deepseek/deepseek-v4-pro
    default_reasoning: medium

    providers:
        deepseek:
            type: generic
            enabled: true
            base_url: https://api.deepseek.com
            api: openai-completions
            api_key: env:DEEPSEEK_API_KEY
            completions_path: /chat/completions
            supports_completions: true
            supports_embeddings: false
            models:
                deepseek-v4-pro:
                    name: DeepSeek V4 Pro
                    context_window: 1000000
                    max_tokens: 384000
                    input: [text]
                    tool_calling: true
                    reasoning: true
                    thinking_level_map:
                        minimal: high
                        low: high
                        medium: high
                        high: high
                        xhigh: max
                    cost:
                        input: 0.435
                        output: 0.87
                        cache_read: 0.003625
                        cache_write: 0
                deepseek-v4-flash:
                    name: DeepSeek V4 Flash
                    context_window: 1000000
                    max_tokens: 384000
                    input: [text]
                    tool_calling: true
                    reasoning: true
                    thinking_level_map:
                        minimal: high
                        low: high
                        medium: high
                        high: high
                        xhigh: max
                    cost:
                        input: 0.14
                        output: 0.28
                        cache_read: 0.0028
                        cache_write: 0

        llama_cpp:
            type: generic
            enabled: true
            base_url: http://192.168.2.38:8052/v1
            api: openai-completions
            api_key: dummy
            completions_path: /chat/completions
            embeddings_path: /embeddings
            supports_completions: true
            supports_embeddings: false
            models:
                flash:
                    name: flash
                    context_window: 200000
                    max_tokens: 65536
                    input: [text, image]
                    tool_calling: true
                    reasoning: false
                    cost:
                        input: 0
                        output: 0
                        cache_read: 0
                        cache_write: 0

        zai:
            type: generic
            enabled: true
            base_url: https://api.z.ai/api/coding/paas/v4
            api: openai-completions
            api_key: env:ZAI_API_KEY
            completions_path: /chat/completions
            supports_completions: true
            supports_embeddings: false
            compatibility:
                supports_developer_role: false
                supports_reasoning_effort: false
                thinking_format: zai
            models:
                glm-5.1:
                    name: GLM 5.1
                    context_window: 200000
                    max_tokens: 131072
                    input: [text]
                    tool_calling: true
                    reasoning: true
                    thinking_level_map:
                        minimal: enabled
                        low: enabled
                        medium: enabled
                        high: enabled
                        xhigh: enabled
                    compatibility:
                        zai_tool_stream: true
                    cost:
                        input: 0
                        output: 0
                        cache_read: 0
                        cache_write: 0
                glm-5v-turbo:
                    name: GLM 5V Turbo
                    context_window: 200000
                    max_tokens: 131072
                    input: [text, image]
                    tool_calling: true
                    reasoning: true
                    thinking_level_map:
                        minimal: enabled
                        low: enabled
                        medium: enabled
                        high: enabled
                        xhigh: enabled
                    compatibility:
                        zai_tool_stream: true
                    cost:
                        input: 0
                        output: 0
                        cache_read: 0
                        cache_write: 0
```

### Reasoning settings

Reasoning should live near model settings and be persisted the same way.

Recommended user-facing values:

```text
off | minimal | low | medium | high | xhigh
```

Initial mapping rules:

- Store `ai.default_reasoning` globally/home-level when user changes it.
- Store current session reasoning in session metadata.
- Reasoning level is global session/user state, not provider-specific state.
- On each turn, look up the selected model in our Hatfield model catalog and map the global reasoning level through that model's `thinking_level_map`.
- If `reasoning: false`, no map exists, or the selected level maps to `null`, omit reasoning options rather than failing.

Provider option mapping should be encapsulated behind a service, not scattered through TUI/runtime code. The service should read rich model metadata from our catalog, not from Symfony bridge catalogs.

Example service responsibility:

```php
interface ReasoningOptionsResolver
{
    /** @return array<string, mixed> */
    public function optionsFor(AiModelReference $model, string $reasoningLevel): array;
}
```

For first pass, the resolver should:

- Return `[]` for `off` or non-reasoning models.
- Use the model's `thinking_level_map` to translate `minimal|low|medium|high|xhigh` into provider values.
- Map the translated value to provider options only where we have confirmed semantics; otherwise omit it.
- For `compatibility.thinking_format: zai`, send `enable_thinking: true` for any mapped non-off value and never send OpenAI-style `reasoning_effort`.
- If `compatibility.supports_reasoning_effort: false`, do not send `reasoning_effort` even when the model has `reasoning: true`.
- Never assume Symfony bridge catalogs know the current model's reasoning support or token limits.

## New application services / DTOs

### Config DTOs

Add under `src/CodingAgent/Config/Ai/` or similar:

- `AiConfig`
  - `?string $defaultModel`
  - `?string $defaultReasoning`
  - `array<string, AiProviderConfig> $providers`
- `AiProviderConfig`
  - `string $id`
  - `string $type` (`generic` initially; bridge-specific types can be added later)
  - `bool $enabled`
  - `string $baseUrl`
  - `string $api` (`openai-completions` initially)
  - `?string $apiKey`
  - provider-specific paths/options
  - `?AiCompatibility $compatibility`
  - `array<string, AiModelDefinition> $models`
- `AiModelDefinition`
  - `string $id`
  - `?string $name`
  - `?int $contextWindow`
  - `?int $maxTokens`
  - `list<string> $input`
  - `bool $toolCalling`
  - `bool $reasoning`
  - `array<string, string|null> $thinkingLevelMap`
  - `?AiCompatibility $compatibility`
  - `?AiCost $cost`
- `AiCompatibility`
  - `?bool $supportsDeveloperRole`
  - `?bool $supportsReasoningEffort`
  - `?string $thinkingFormat` (`zai` initially)
  - `?bool $zaiToolStream`
  - Keep this intentionally small and explicit; it documents provider/model transport quirks we actually consume.
- `AiModelReference`
  - `string $providerId`
  - `string $modelName`
  - parse/format `provider/model`

Extend `AppConfig::fromArray()` to expose typed AI config while keeping `raw` for unknown future keys. This becomes our catalog/registry source of truth; Symfony model catalogs are generated from it.

### Secret resolution

Add a small resolver for settings values:

```text
null                  -> null
plain-string          -> plain-string
env:NAME              -> getenv('NAME') or null
cmd:...               -> later, not first pass
```

Do not write resolved secrets back to settings or session metadata.

### Provider/platform construction

Add:

- `ConfiguredSymfonyAiProviderFactory`
- `ConfiguredSymfonyAiPlatformFactory`
- `ConfiguredModelRegistry`
- `HatfieldModelCatalog`
- `SymfonyModelCatalogProjector`

`ConfiguredSymfonyAiPlatformFactory` should build a single multi-provider Symfony platform:

```php
new Symfony\AI\Platform\Platform(
    providers: $providers,
    modelRouter: $router,
    eventDispatcher: $eventDispatcher,
);
```

Provider IDs from settings must become Symfony provider names. For first pass, treat DeepSeek, z.ai, and llama.cpp as OpenAI chat-completions-style generic providers.

```php
Generic\Factory::createProvider(
    baseUrl: $providerConfig->baseUrl,
    apiKey: $apiKey,
    httpClient: $httpClient,
    modelCatalog: $symfonyCatalogProjector->forProvider($providerConfig),
    eventDispatcher: $eventDispatcher,
    supportsCompletions: $providerConfig->supportsCompletions,
    supportsEmbeddings: $providerConfig->supportsEmbeddings,
    completionsPath: $providerConfig->completionsPath,
    embeddingsPath: $providerConfig->embeddingsPath,
    name: $providerConfig->id,
);
```

Do not use `symfony/ai-deep-seek-platform` initially. Its client hardcodes the DeepSeek endpoint path and its built-in catalog is not a source of truth for the models we want. Use the generic provider with `base_url: https://api.deepseek.com` and `completions_path: /chat/completions`.

## Hatfield model catalog

Our catalog is an application-level model registry, similar in spirit to Pi's `models.json`. It owns model characteristics needed by the agent:

- context window,
- max output tokens,
- cost,
- input modalities,
- tool-calling support,
- reasoning support,
- thinking/reasoning level map,
- provider/model compatibility quirks that affect request shaping or stream parsing,
- Symfony capability projection.

Symfony model catalogs should be generated views over this registry. Their role is only to satisfy Symfony Platform routing and model construction.

### Compatibility metadata

We need a small compatibility layer for providers that are OpenAI chat-completions-style but not actually OpenAI-compatible in every detail. This is internal Hatfield metadata, not something Symfony's generic bridge understands by itself.

Initial supported compatibility keys:

- `supports_developer_role: false` — do not emit OpenAI `developer` role; use system/user/assistant/tool roles only.
- `supports_reasoning_effort: false` — do not send `reasoning_effort`.
- `thinking_format: zai` — z.ai uses `enable_thinking: boolean` instead of OpenAI reasoning effort.
- `zai_tool_stream: true|false` — model-level note for z.ai streaming tool-call behavior.

The option/message mapping layer should consume compatibility metadata and produce final Symfony invocation options/messages. Do not rely on Symfony bridge model catalogs for these quirks.

### Model availability policy

All providers should be explicit: only models listed in the Hatfield model catalog are selectable and routable.

This applies to remote providers such as z.ai and DeepSeek, and also to local llama.cpp. Even though llama.cpp can technically serve arbitrary loaded models, the user still needs to add the model to settings so Hatfield has the model name, context window, max tokens, input modalities, tool-calling support, reasoning behavior, and cost metadata.

For the provided llama.cpp `flash` entry, tool-calling works and should be recorded on that explicit model.

Settings should use Hatfield/YAML snake_case even when copied from Pi's JSON shape:

- `baseUrl` -> `base_url`
- `apiKey` -> `api_key`
- `contextWindow` -> `context_window`
- `maxTokens` -> `max_tokens`
- `thinkingLevelMap` -> `thinking_level_map`
- `supportsDeveloperRole` -> `supports_developer_role`
- `supportsReasoningEffort` -> `supports_reasoning_effort`
- `zaiToolStream` -> `zai_tool_stream`
- `cacheRead` / `cacheWrite` -> `cache_read` / `cache_write`

### Symfony catalog projection

Create a projector from `AiProviderConfig` + `AiModelDefinition` into Symfony's thin catalog format:

```php
namespace Ineersa\CodingAgent\Ai\Symfony;

use Symfony\AI\Platform\Capability;
use Symfony\AI\Platform\Bridge\Generic\Generic;
use Symfony\AI\Platform\ModelCatalog\AbstractModelCatalog;

final class ProjectedSymfonyModelCatalog extends AbstractModelCatalog
{
    /** @param array<string, AiModelDefinition> $models */
    public function __construct(array $models)
    {
        foreach ($models as $model) {
            $this->models[$model->id] = [
                'class' => Generic::class,
                'capabilities' => $this->capabilitiesFor($model),
            ];
        }
    }

    /** @return list<Capability> */
    private function capabilitiesFor(AiModelDefinition $model): array
    {
        // INPUT_MESSAGES + OUTPUT_TEXT + OUTPUT_STREAMING by default;
        // TOOL_CALLING/THINKING only when our metadata says so.
    }
}
```

`HatfieldModelCatalog` remains the authoritative API for UI/model selection/reasoning/cost/token information. `ProjectedSymfonyModelCatalog` should not grow those concerns.

## Model selection behavior

Create `ModelSelectionService` with these responsibilities:

```php
resolveInitialModel(string $cwd, string $sessionId = '', ?string $explicit = null): AiModelReference
getCurrentModel(string $cwd, string $sessionId): AiModelReference
changeModel(string $cwd, string $sessionId, AiModelReference $model): void
getAvailableModels(): list<AiModelReference>
```

Resolution order:

1. Explicit request/CLI option.
2. Session metadata current model.
3. Home/project Hatfield `ai.default_model`.
4. First available configured model.

Availability means:

- provider exists and is enabled,
- provider-specific required settings are present,
- required secret resolves successfully,
- model is listed in the Hatfield model catalog.

### Model change persistence

When user changes model:

1. Validate model is available.
2. Update home/global Hatfield setting `ai.default_model`.
3. Update session metadata with current model.
4. Runtime/TUI can later emit/display `model_changed`.

Session metadata should include canonical and split fields for easier debugging:

```yaml
model: deepseek/deepseek-chat
model_provider: deepseek
model_name: deepseek-chat
reasoning: medium
```

## Reasoning selection behavior

Create `ReasoningSelectionService` or fold into model selection with clear methods:

```php
resolveInitialReasoning(string $cwd, string $sessionId = '', ?string $explicit = null): string
changeReasoning(string $cwd, string $sessionId, string $level): void
getCurrentReasoning(string $cwd, string $sessionId): string
```

Resolution order mirrors model:

1. Explicit request/CLI option.
2. Session metadata current reasoning.
3. Hatfield `ai.default_reasoning`.
4. Built-in default, probably `medium`.

When user changes reasoning:

1. Validate enum value.
2. Save to home/global Hatfield setting `ai.default_reasoning`.
3. Save to session metadata `reasoning`.
4. Apply from the next LLM turn via model resolver/options hook.

## Passing model/reasoning into AgentCore

### Initial run

`StartRunRequest::$options` can carry initial values without changing constructor shape immediately:

```php
new StartRunRequest(
    prompt: $prompt,
    runId: $sessionId,
    cwd: $cwd,
    options: [
        'model' => 'deepseek/deepseek-v4-pro',
        'reasoning' => 'medium',
    ],
);
```

`InProcessAgentSessionClient::start()` should map these into `RunMetadata`:

```php
new StartRunInput(
    systemPrompt: $request->prompt,
    messages: [],
    runId: '' !== $request->runId ? $request->runId : null,
    metadata: new RunMetadata(
        session: [
            'cwd' => $request->cwd,
            'reasoning' => $request->options['reasoning'] ?? null,
        ],
        model: $request->options['model'] ?? null,
    ),
);
```

### Per-turn resolution

Use the existing Symfony AI `ModelRoutingEvent` path. Implement a production `ModelResolverInterface` that:

1. Reads current model/reasoning from session metadata using `ModelInvocationInput::$runId`.
2. Parses `provider/model` into `AiModelReference`.
3. Returns raw model name plus options merged with reasoning options.
4. Also communicates selected provider to routing.

Current `ResolvedModel` lacks provider information. Extend it:

```php
final readonly class ResolvedModel
{
    public function __construct(
        public string $model,
        public array $options = [],
        public ?string $provider = null,
    ) {}
}
```

Then update `ModelResolverRoutingSubscriber` to set provider when present:

```php
$event->setModel($resolved->model);
$event->setOptions(array_replace($options, $resolved->options));

if (null !== $resolved->provider) {
    $event->setProvider($providerRegistry->get($resolved->provider));
}
```

This avoids ambiguous routing when multiple generic providers can serve the same raw model name.

## Routing support

Add a provider registry for built providers:

```php
final class SymfonyAiProviderRegistry
{
    /** @param array<string, ProviderInterface> $providersById */
    public function __construct(private array $providersById) {}

    public function get(string $id): ProviderInterface;
    /** @return list<ProviderInterface> */
    public function all(): array;
}
```

Use this registry both to construct the platform and to support `ModelRoutingEvent::setProvider()`.

## DI wiring

The Symfony AI 0.9 baseline already includes the generic provider bridge needed for the first implementation. Do not add extra bridge packages unless implementation proves they are missing.

Do not add `symfony/ai-deep-seek-platform` for the initial implementation; route DeepSeek through the generic chat-completions provider.

Wire aliases:

```yaml
services:
    Symfony\AI\Platform\PlatformInterface:
        factory: ['@Ineersa\CodingAgent\Ai\Symfony\ConfiguredSymfonyAiPlatformFactory', 'create']

    Ineersa\AgentCore\Contract\Tool\PlatformInterface:
        alias: Ineersa\AgentCore\Infrastructure\SymfonyAi\LlmPlatformAdapter

    Ineersa\AgentCore\Contract\Tool\ModelResolverInterface:
        alias: Ineersa\CodingAgent\Ai\Model\HatfieldModelResolver
```

Also bind `ExecuteLlmStepWorker::$defaultModel` to a safe configured default. The per-turn resolver should normally override it, but the worker still needs a non-empty model string.

## TUI/model controls and footer

Model/reasoning selection must be visible and controllable from the TUI. This is part of the platform work because model choice drives catalog lookup, context limits, costs, and reasoning options.

TUI must stay behind the runtime boundary: `src/Tui/` reads runtime DTOs/projections and sends runtime commands; it must not import AgentCore services directly.

### Model picker

Add a `/model` command that opens an overlay/list of all available provider models from `ConfiguredModelRegistry::getAvailableModels()`.

Behavior:

- List starts with favorite models, then all other available models grouped or labelled by provider.
- Arrow keys / normal list navigation scroll the list.
- `Enter` selects the highlighted model.
- `Ctrl+F` toggles the highlighted model as a favorite.
- Selection persists:
  - current session model in session metadata,
  - user default model in home Hatfield settings.
- Favorites persist in home Hatfield settings, e.g. `ai.favorite_models` as a list of `provider/model` refs.

### Fast favorite cycling

`Ctrl+P` cycles through favorite models without opening the picker.

Rules:

- Cycle order follows `ai.favorite_models`.
- Selecting a favorite applies the same persistence/event path as selecting from `/model`.
- If favorites are empty, either no-op with a status message or open `/model`.

### Thinking/reasoning control

`Shift+Tab` cycles through thinking levels:

```text
off -> minimal -> low -> medium -> high -> xhigh -> off
```

Rules:

- Current level persists to session metadata and home Hatfield settings.
- Runtime emits a reasoning/thinking change event.
- Next LLM turn resolves provider options through the selected model's `thinking_level_map`.

### Footer

Show model, thinking level, token/cost/context, throughput, session time, cwd, and branch in the footer. Target shape inspired by the Pi custom footer:

```text
◆ gpt-5.5  | high |  328.3k/35.0k $5.47 25% 67.4k/272.0k  |  ⚡ 37.8 t/s  |  ⏱ 4h6m  |  ⌂ projects/agent-core  |  ⎇ main
```

Segments:

- current model display name/id,
- current thinking level,
- accumulated input/output tokens,
- accumulated session cost,
- context window usage percent and `used/context_window`,
- latest tokens/sec from the most recent model turn,
- elapsed session time,
- shortened cwd,
- git branch.

Footer data should come from session/runtime projections:

- model/reasoning from session metadata or runtime state,
- token usage from assistant message usage metadata accumulated across the session,
- context window from selected `AiModelDefinition.contextWindow`,
- cost from selected model pricing and token usage,
- tokens/sec from model turn timing and output token count,
- cwd/branch from existing footer/session infrastructure.

Runtime events:

- `model_changed`
- `reasoning_changed`
- `usage_updated` or equivalent projection update after assistant turns

## Validation plan

Prefer application/integration tests over a large mock-heavy unit suite.

### Trace replay application tests

Build a replay harness around real recorded model sessions/traces:

1. Run a real session/model invocation and persist enough information to replay it:
   - selected `provider/model`, reasoning level, and invocation options,
   - input messages/tool definitions if relevant,
   - streamed deltas or normalized assistant message,
   - usage metadata,
   - session metadata/state/transcript/runtime events.
2. Store curated trace fixtures under tests, with secrets and machine-local paths stripped.
3. Replay the trace through a fake Symfony provider/platform that emits the recorded deltas/results.
4. Assert the application-level outcome:
   - model and reasoning were resolved correctly,
   - assistant message/tool calls were persisted correctly,
   - usage/cost/context projections update correctly,
   - footer/status projection receives expected model/thinking/token/tps data,
   - resume/replay uses session metadata rather than global defaults.

This should exercise the real AgentCore + runtime flow with only the provider boundary faked.

### Real-provider smoke test

Add at least one opt-in real model test against llama.cpp.

Suggested behavior:

- Uses Hatfield `llama_cpp` provider config or env overrides such as `LLAMA_CPP_BASE_URL` and `LLAMA_CPP_MODEL`.
- Starts a small run with a deterministic prompt.
- Verifies a non-empty assistant response, usage if available, selected model persistence, and footer/runtime usage projection.
- Mark as an external/integration group so it is not required by default `castor check` unless the environment is configured.

### Focused unit tests

Keep focused unit tests for pure configuration/model-registry behavior:

- Config parsing/merge for `ai` section.
- Secret resolver (`env:VAR`, null, plain values).
- `AiModelReference` parsing/formatting.
- Hatfield model catalog parses rich model metadata: context window, max tokens, cost, input modalities, tool-calling, reasoning, thinking-level map, and compatibility metadata.
- Projected Symfony catalog supports only configured models for every provider.
- Model/favorite selection priority: explicit > session > settings > first available.
- Reasoning selection priority mirrors model selection.
- z.ai compatibility mapping sends `enable_thinking` for non-off reasoning and never sends `reasoning_effort`.

Full default validation:

```bash
castor check
```

External validation when llama.cpp is configured:

```bash
# exact Castor task/name TBD
castor test:llm-real
```

## Implementation task graph

Task IDs below are planning references for tracked task files/PRs. Keep each task small enough for one implementation pass by a smaller model.

### Dependency map

```text
Prereq: Symfony AI 0.9 upgrade merged

AI-01 Settings schema/docs
  ├─ AI-02 Catalog DTOs and parser
  │   ├─ AI-04 Symfony catalog projector
  │   │   └─ AI-05 Configured generic provider platform
  │   ├─ AI-06 Reasoning/compatibility option resolver
  │   │   └─ AI-09 z.ai request-shaping integration
  │   └─ AI-07 Model selection service
  │       └─ AI-10 AgentCore per-turn model routing
  ├─ AI-03 Home settings bootstrap/writer
  │   └─ AI-07 Model selection service
  └─ AI-08 Runtime protocol and CLI model/reasoning inputs
      └─ AI-10 AgentCore per-turn model routing

AI-05 + AI-06 + AI-10 ──┬─ AI-11 Trace replay application tests
                         ├─ AI-12 Real llama.cpp smoke test
                         └─ AI-13 Footer usage/model projection
                              └─ AI-14 TUI model controls
```

Parallel batches:

- Batch A: `AI-01` only.
- Batch B after `AI-01`: `AI-02`, `AI-03`, and `AI-08` can run in parallel.
- Batch C after `AI-02`: `AI-04`, `AI-06`, and most of `AI-07` can run in parallel; `AI-07` also needs `AI-03` for persistence writes.
- Batch D after `AI-04`: `AI-05` can run while `AI-10` preparation continues.
- Batch E after provider + routing path exists: `AI-11`, `AI-12`, and `AI-13` can run in parallel.
- Batch F: `AI-14` after `AI-07`, `AI-08`, and `AI-13`.

### AI-01 — Add AI settings shape, defaults, and docs

Goal: introduce the user-facing `ai` config section without changing runtime behavior.

Scope:

- Update `config/hatfield.defaults.yaml` with commented/default `ai` shape.
- Update committed `.hatfield/settings.yaml` example comments.
- Update `docs/settings.md` with home/project precedence and examples.
- Document configured providers: `deepseek`, `llama_cpp`, `zai`.
- Document that every selectable model must be explicitly listed.
- Include first seed models:
  - `deepseek/deepseek-v4-pro`
  - `deepseek/deepseek-v4-flash`
  - `llama_cpp/flash`
  - `zai/glm-5.1`
  - `zai/glm-5v-turbo`
- Include z.ai compatibility notes: no developer role, no reasoning effort, `thinking_format: zai`, `zai_tool_stream` on supported models.

Acceptance:

- Existing settings still load when `ai` is absent.
- Docs and examples use snake_case keys.
- No provider construction or model selection behavior changes yet.

Suggested validation:

```bash
castor test
```

### AI-02 — Implement AI config DTOs and Hatfield model catalog

Goal: parse the `ai` settings into typed structures and expose an authoritative model catalog.

Scope:

- Add DTOs under `src/CodingAgent/Config/Ai/` or equivalent:
  - `AiConfig`
  - `AiProviderConfig`
  - `AiModelDefinition`
  - `AiCost`
  - `AiCompatibility`
  - `AiModelReference`
- Extend `AppConfig::fromArray()` to parse `ai` while preserving unknown/raw settings.
- Implement `HatfieldModelCatalog` with methods roughly equivalent to:
  - `getProvider(string $id): ?AiProviderConfig`
  - `getModel(AiModelReference|string $ref): ?AiModelDefinition`
  - `requireModel(AiModelReference|string $ref): AiModelDefinition`
  - `allModels(): list<AiModelReference>`
  - `isAvailable(AiModelReference|string $ref): bool` for configured/enabled/listed models only.
- Explicit-only behavior: unknown model names are rejected for every provider, including llama.cpp.

Acceptance:

- Rich model metadata parses: context window, max tokens, input modalities, tool-calling, reasoning, thinking map, cost, compatibility.
- `provider/model` parsing rejects malformed values and unknown providers/models.
- llama.cpp only exposes listed models such as `llama_cpp/flash`.

Suggested validation:

```bash
castor test --filter Ai
castor phpstan
```

### AI-03 — Home settings bootstrap and comment-preserving settings writer

Goal: support user defaults/favorites without destroying hand-written settings comments.

Scope:

- On startup/config resolution, if `~/.hatfield/settings.yaml` is missing, initialize it from documented defaults/examples.
- Add a small home settings writer service for machine-managed changes:
  - update `ai.default_model`
  - update `ai.default_reasoning`
  - later update model favorites
- Preserve existing comments and unrelated keys where possible.
- If perfect comment preservation is not possible with the existing YAML stack, constrain writes to targeted scalar replacements and fail safely rather than rewriting the whole file.

Acceptance:

- Missing home settings file is created once.
- Updating model/reasoning does not remove existing comments from the file.
- Project `.hatfield/settings.yaml` remains the example/project override file; do not recreate `.hatfield.example/`.

Suggested validation:

```bash
castor test --filter Settings
```

### AI-04 — Project Hatfield model catalog into Symfony model catalogs

Goal: create thin Symfony model catalogs from Hatfield metadata for Platform routing/capability checks.

Scope:

- Implement `ProjectedSymfonyModelCatalog` or equivalent.
- For each configured model, project to Symfony `Generic::class` with capabilities:
  - messages input
  - text output
  - streaming output
  - tool calling when `tool_calling: true`
  - thinking when `reasoning: true`
- Do not carry cost/context/favorites into Symfony catalog classes.
- Unknown models must not be supported.

Acceptance:

- Projected catalog supports only listed models.
- Capabilities reflect Hatfield metadata.
- No use of Symfony built-in DeepSeek/z.ai catalogs as source of truth.

Suggested validation:

```bash
castor test --filter SymfonyModelCatalog
```

### AI-05 — Build configured Symfony generic providers/platform

Goal: instantiate a single multi-provider Symfony AI Platform from Hatfield settings.

Scope:

- Build `SymfonyAiProviderFactory` using Symfony generic bridge factory.
- Build provider registry keyed by Hatfield provider ID.
- Build `ConfiguredSymfonyAiPlatformFactory` returning `Symfony\AI\Platform\Platform` with:
  - all enabled configured providers,
  - projected catalogs,
  - Symfony event dispatcher passed into Platform/provider construction.
- Wire DI aliases for Symfony `PlatformInterface` and AgentCore `PlatformInterface` adapter.
- Bind a safe configured default model for `ExecuteLlmStepWorker::$defaultModel` until per-turn routing overrides it.
- DeepSeek uses generic provider with `base_url: https://api.deepseek.com` and `completions_path: /chat/completions`.

Acceptance:

- Container compiles with configured generic providers.
- Existing fake/provider tests still pass.
- No `symfony/ai-deep-seek-platform` dependency is required.

Suggested validation:

```bash
castor test --filter Platform
castor deptrac
```

### AI-06 — Implement reasoning and compatibility option resolver

Goal: convert global reasoning level + model metadata into provider invocation options.

Scope:

- Implement `ReasoningOptionsResolver`.
- Inputs: `AiModelReference`, user-facing level `off|minimal|low|medium|high|xhigh`.
- Behavior:
  - return `[]` for `off`, non-reasoning models, missing map, or null map value;
  - use `thinking_level_map` for model-specific translation;
  - for `compatibility.thinking_format: zai`, emit `enable_thinking: true` for mapped non-off levels;
  - for `supports_reasoning_effort: false`, never emit `reasoning_effort`;
  - leave room for future OpenAI-style mappings but do not invent unsupported semantics.

Acceptance:

- z.ai maps every non-off configured level to `enable_thinking: true`.
- llama.cpp `flash` produces no reasoning options.
- Unit tests prove `reasoning_effort` is omitted when unsupported.

Suggested validation:

```bash
castor test --filter Reasoning
```

### AI-07 — Implement model/reasoning selection and persistence service

Goal: centralize model/reasoning selection priority and persistence.

Scope:

- Implement `ModelSelectionService` and `ReasoningSelectionService` or one cohesive service.
- Model resolution priority:
  1. explicit request/CLI/runtime input,
  2. session metadata,
  3. Hatfield `ai.default_model`,
  4. first available configured model.
- Reasoning resolution priority mirrors model selection, falling back to `ai.default_reasoning` then `medium` or `off` if needed.
- On change:
  - update home `ai.default_model` / `ai.default_reasoning`,
  - update session metadata current fields,
  - expose enough info for runtime/TUI events later.
- Validate every selected model against `HatfieldModelCatalog`.

Acceptance:

- New sessions use configured default model.
- Resumed sessions use session metadata model/reasoning.
- Changing model/reasoning persists both home defaults and session current state.

Suggested validation:

```bash
castor test --filter ModelSelection
```

### AI-08 — Add runtime protocol and CLI inputs for model/reasoning

Goal: allow initial model/reasoning to enter the system from CLI/TUI/process clients.

Scope:

- Extend `StartRunRequest` with optional `model` and `reasoning` fields.
- Extend JSONL protocol payloads for process runtime.
- Update `AgentCommand` CLI options: `--model`, `--reasoning`.
- Update `InteractiveMode`, `SessionInitializer`, `SubmitListener`, `InProcessAgentSessionClient`, and `JsonlProcessAgentSessionClient` as needed to preserve and forward fields.
- Keep backward compatibility when fields are absent.

Acceptance:

- Headless and TUI starts can pass model/reasoning.
- Existing start-run call sites compile and work with null fields.
- JSONL clients ignore/omit absent fields safely.

Suggested validation:

```bash
castor test --filter Runtime
castor deptrac
```

### AI-09 — Apply compatibility-aware message/options shaping before provider invocation

Goal: ensure generic providers receive provider-specific request shapes without scattering compatibility checks.

Scope:

- Integrate `ReasoningOptionsResolver` into the existing pre-provider hook/subscriber path.
- Add a focused mapper for provider/model compatibility quirks.
- z.ai behavior:
  - no OpenAI `developer` role if `supports_developer_role: false`,
  - send `enable_thinking` for non-off reasoning,
  - do not send `reasoning_effort`,
  - keep tool-call streaming expectation documented by `zai_tool_stream`.
- Keep response parsing in existing Symfony AI 0.9 adapter/converter unless real provider traces prove a gap.

Acceptance:

- Invocation options for `zai/glm-5.1` include `enable_thinking` when reasoning is non-off.
- Invocation options for z.ai never include `reasoning_effort`.
- Message conversion does not emit unsupported developer role for providers that disable it.

Suggested validation:

```bash
castor test --filter Compat
castor test --filter PlatformIntegration
```

### AI-10 — Route per-turn model/reasoning through AgentCore

Goal: replace hardcoded model behavior with per-turn resolved model and provider routing.

Scope:

- Extend `ResolvedModel` with provider ID.
- Implement production `ModelResolverInterface` backed by selection services and run/session metadata.
- Update `ExecuteLlmStepWorker`/`ModelInvocationRequest` flow to use resolved model/options rather than only hardcoded default.
- Update `ModelResolverRoutingSubscriber` to call `ModelRoutingEvent::setProvider()` using provider registry when provider ID is present.
- Ensure `RunMetadata.model` is populated and used.

Acceptance:

- Each LLM turn invokes Symfony Platform with raw model name and explicit provider selection.
- New session, resumed session, and explicit CLI model follow the documented priority order.
- Existing tests around LLM execution continue to pass.

Suggested validation:

```bash
castor test --filter ExecuteLlmStepWorker
castor test --filter ModelResolver
castor deptrac
```

### AI-11 — Add trace replay application tests

Goal: validate the full application path using recorded/replayed provider output.

Scope:

- Build a replay provider/platform fixture that emits recorded streamed deltas or normalized results.
- Add curated trace fixture(s) stripped of secrets/local paths.
- Exercise AgentCore + runtime flow at application level.
- Assert:
  - model/reasoning resolution,
  - message/tool-call persistence,
  - usage/cost/context projections,
  - resume uses session metadata, not current global default.

Acceptance:

- At least one replay test covers a successful assistant response.
- At least one replay test covers model/reasoning persistence across resume.
- Tests run without network access.

Suggested validation:

```bash
castor test --filter TraceReplay
```

### AI-12 — Add opt-in real llama.cpp smoke test

Goal: prove the configured generic provider can call the real local llama.cpp endpoint when available.

Scope:

- Add an opt-in external/integration test group or Castor task.
- Read provider details from Hatfield settings or env overrides:
  - `LLAMA_CPP_BASE_URL`
  - `LLAMA_CPP_MODEL`
- Use a tiny deterministic prompt.
- Assert non-empty assistant response and selected model persistence.
- Capture usage if provider returns it, but do not fail if usage is absent.

Acceptance:

- Test is skipped unless explicitly configured.
- Default `castor check` is not blocked by missing llama.cpp.
- Document how to run it.

Suggested validation:

```bash
# exact Castor task/name TBD
castor test:llm-real
```

### AI-13 — Footer/status projection for model, reasoning, usage, and cost

Goal: expose runtime data needed by the footer without implementing the full picker yet.

Scope:

- Add runtime/projection events or state for:
  - current model,
  - current reasoning level,
  - token usage,
  - cost estimate,
  - context window usage,
  - tokens/sec,
  - session elapsed time,
  - cwd and git branch if not already exposed.
- Use `FooterSegmentProvider`/`FooterDataProvider` extension points, not direct widget mutation.
- Format toward:
  - `◆ model | thinking | tokens cost context% | ⚡ t/s | ⏱ time | ⌂ cwd | ⎇ branch`

Acceptance:

- Footer can show selected model and reasoning after run start.
- Usage/cost/context update after assistant result when metadata is available.
- TUI boundary stays clean: `src/Tui/` does not import AgentCore internals.

Suggested validation:

```bash
castor test --filter Footer
castor deptrac
```

### AI-14 — TUI model controls and favorites

Goal: add user controls for switching models/reasoning from the TUI.

Scope:

- `/model` overlay/list:
  - favorites first,
  - all configured provider models after favorites,
  - scrollable,
  - `Ctrl+F` toggles favorite,
  - `Enter` selects.
- `Ctrl+P` cycles favorite models.
- `Shift+Tab` cycles reasoning levels: `off -> minimal -> low -> medium -> high -> xhigh -> off`.
- Persist model/reasoning changes through selection services.
- Emit/update runtime events so footer changes immediately.

Acceptance:

- User can select a model before the next turn.
- Favorite cycling only cycles configured/favorited models.
- Reasoning cycle updates session/home defaults and footer state.
- Existing TUI snapshots are updated only if rendering intentionally changes.

Suggested validation:

```bash
castor test --filter Tui
castor test:tui
```

## Resolved decisions and remaining questions

Resolved:

- z.ai provider ID should be `zai`, base URL `https://api.z.ai/api/coding/paas/v4`, env key `ZAI_API_KEY`, and default candidate model `glm-5.1`.
- z.ai direct-provider models are explicit catalog entries. Pi currently knows `glm-4.5-air`, `glm-4.7`, `glm-5-turbo`, `glm-5.1`, and `glm-5v-turbo`; seed only the models we intend to expose first and expand later.
- z.ai reasoning is binary from our perspective: map any non-off thinking level through `thinking_level_map` to an enabled value and send `enable_thinking`, not `reasoning_effort`.
- z.ai requires explicit compatibility metadata for OpenAI-different behavior: no developer role, no reasoning effort, `thinking_format: zai`, and model-level `zai_tool_stream`.
- llama.cpp `flash` metadata comes from the provided Pi config: 200k context, 65,536 max tokens, text+image input, zero cost, and tool-calling enabled.
- DeepSeek should use the generic chat-completions provider, not the Symfony DeepSeek bridge.
- Reasoning level is global user/session state; model-specific maps translate it to provider values.
- Home settings writes should preserve comments, and missing `~/.hatfield/settings.yaml` should be initialized from documented defaults/examples on launch.
- Project settings may override `ai.default_model` because this falls out of existing Hatfield config precedence.

Remaining:

- Confirm the exact first z.ai model set to expose beyond `glm-5.1` and `glm-5v-turbo`.
