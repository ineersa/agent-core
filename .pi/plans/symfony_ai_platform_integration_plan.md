# Symfony AI Platform Integration Plan

## Status

Planning draft created 2026-05-16.

This plan covers wiring Symfony AI Platform into Hatfield/AgentCore after the separate Symfony AI `0.9` upgrade task lands. The goal is to make the existing `LlmPlatformAdapter` talk to configured real providers, persist model/reasoning selection, and allow per-turn model changes.

## Scope decisions

### In scope for first implementation

- Configure and instantiate Symfony AI Platform providers from Hatfield settings.
- Support only these provider families initially:
  - `deepseek` via `symfony/ai-deep-seek-platform`.
  - `generic` OpenAI-compatible providers via `symfony/ai-generic-platform`.
- Add small custom model catalogs for generic providers so llama.cpp and z.ai models do not rely on `FallbackModelCatalog` accepting everything.
- Persist selected model and reasoning level in:
  - global/home Hatfield settings as user defaults,
  - session metadata as current session state.
- Pass current model and reasoning level into AgentCore for every LLM turn.
- Keep model IDs user-facing as `provider_id/model_name`, while passing raw `model_name` to Symfony AI.

### Out of scope for first implementation

- Codex bridge integration. Symfony's Codex bridge shells out to the local `codex` CLI and is not the proper Codex provider support we expected. Build first-party/proper Codex support later.
- OpenAI/Anthropic/Ollama first-class provider setup. They can be added after the generic/deepseek path is working.
- Fancy TUI model picker. Start with settings + internal services; add `/model` and picker later.
- Symfony AI `0.9` upgrade itself. That is tracked separately and should land first.

## Desired settings shape

Hatfield settings should grow an `ai` section. Home settings are the right place for personal defaults and secrets references. Project settings can override shared project-local providers/models.

```yaml
ai:
    default_model: deepseek/deepseek-chat
    default_reasoning: medium

    providers:
        deepseek:
            type: deepseek
            enabled: true
            api_key: env:DEEPSEEK_API_KEY
            models:
                - deepseek-chat
                - deepseek-reasoner

        llama_cpp:
            type: generic
            enabled: true
            base_url: http://127.0.0.1:8080
            api_key: null
            completions_path: /v1/chat/completions
            embeddings_path: /v1/embeddings
            supports_completions: true
            supports_embeddings: false
            catalog: llama_cpp
            models:
                - llama-3.3-70b-instruct

        z_ai:
            type: generic
            enabled: true
            base_url: https://api.z.ai/api/paas/v4
            api_key: env:Z_AI_API_KEY
            completions_path: /chat/completions
            supports_completions: true
            supports_embeddings: false
            catalog: z_ai
            models:
                - glm-4.6
```

### Reasoning settings

Reasoning should live near model settings and be persisted the same way.

Recommended user-facing values:

```text
off | low | medium | high | xhigh
```

Initial mapping rules:

- Store `ai.default_reasoning` globally/home-level when user changes it.
- Store current session reasoning in session metadata.
- On each turn, pass provider-specific invocation options derived from the current reasoning level.
- If a model/provider does not support reasoning, omit reasoning options rather than failing.

Provider option mapping should be encapsulated behind a service, not scattered through TUI/runtime code.

Example service responsibility:

```php
interface ReasoningOptionsResolver
{
    /** @return array<string, mixed> */
    public function optionsFor(AiModelRef $model, string $reasoningLevel): array;
}
```

For first pass, this can return conservative generic options:

- `off`: `[]`
- `low|medium|high|xhigh`: provider-specific mapping if known, otherwise `[]`

DeepSeek likely needs special handling for `deepseek-reasoner`; do not assume OpenAI/Anthropic option names apply.

## New application services / DTOs

### Config DTOs

Add under `src/CodingAgent/Config/Ai/` or similar:

- `AiConfig`
  - `?string $defaultModel`
  - `?string $defaultReasoning`
  - `array<string, AiProviderConfig> $providers`
- `AiProviderConfig`
  - `string $id`
  - `string $type` (`deepseek`, `generic` initially)
  - `bool $enabled`
  - provider-specific options (`apiKey`, `baseUrl`, paths, catalog, models, etc.)
- `AiModelRef`
  - `string $providerId`
  - `string $modelName`
  - parse/format `provider/model`

Extend `AppConfig::fromArray()` to expose typed AI config while keeping `raw` for forward compatibility.

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

`ConfiguredSymfonyAiPlatformFactory` should build a single multi-provider Symfony platform:

```php
new Symfony\AI\Platform\Platform(
    providers: $providers,
    modelRouter: $router,
    eventDispatcher: $eventDispatcher,
);
```

Provider IDs from settings must become Symfony provider names:

```php
DeepSeek\Factory::createProvider(
    apiKey: $apiKey,
    httpClient: $httpClient,
    modelCatalog: $catalog,
    eventDispatcher: $eventDispatcher,
    name: $providerConfig->id,
);
```

For generic providers:

```php
Generic\Factory::createProvider(
    baseUrl: $providerConfig->baseUrl,
    apiKey: $apiKey,
    httpClient: $httpClient,
    modelCatalog: $catalog,
    eventDispatcher: $eventDispatcher,
    supportsCompletions: $providerConfig->supportsCompletions,
    supportsEmbeddings: $providerConfig->supportsEmbeddings,
    completionsPath: $providerConfig->completionsPath,
    embeddingsPath: $providerConfig->embeddingsPath,
    name: $providerConfig->id,
);
```

## Custom generic catalogs

Do not use the generic bridge's `FallbackModelCatalog` for configured providers by default. It makes every model look supported and weakens routing/validation.

Create a small configurable catalog for OpenAI-compatible generic providers:

```php
namespace Ineersa\CodingAgent\Ai\Symfony;

use Symfony\AI\Platform\Capability;
use Symfony\AI\Platform\Bridge\Generic\Generic;
use Symfony\AI\Platform\ModelCatalog\AbstractModelCatalog;

final class ConfiguredGenericModelCatalog extends AbstractModelCatalog
{
    /**
     * @param list<string> $modelNames
     * @param list<Capability> $capabilities
     */
    public function __construct(array $modelNames, array $capabilities = [])
    {
        $capabilities = [] !== $capabilities ? $capabilities : [
            Capability::INPUT_MESSAGES,
            Capability::OUTPUT_TEXT,
            Capability::OUTPUT_STREAMING,
            Capability::TOOL_CALLING,
        ];

        foreach ($modelNames as $modelName) {
            $this->models[$modelName] = [
                'class' => Generic::class,
                'capabilities' => $capabilities,
            ];
        }
    }
}
```

Then add catalog presets that just choose capabilities:

- `llama_cpp`: likely chat + streaming, tool-calling only if the local server supports it.
- `z_ai`: chat + streaming + tool-calling, reasoning only if confirmed.
- `generic`: default conservative chat + streaming.

The user can fill model lists later through settings.

## Model selection behavior

Create `ModelSelectionService` with these responsibilities:

```php
resolveInitialModel(string $cwd, string $sessionId = '', ?string $explicit = null): AiModelRef
getCurrentModel(string $cwd, string $sessionId): AiModelRef
changeModel(string $cwd, string $sessionId, AiModelRef $model): void
getAvailableModels(): list<AiModelRef>
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
- model is listed in config or known by the provider catalog.

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
        'model' => 'deepseek/deepseek-chat',
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
2. Parses `provider/model` into `AiModelRef`.
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

After the 0.9 upgrade task lands, add bridge packages:

```bash
composer require symfony/ai-deep-seek-platform:^0.9 symfony/ai-generic-platform:^0.9 --with-dependencies
```

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

Also bind `ExecuteLlmStepWorker::$defaultModel` to a safe configured fallback. The per-turn resolver should normally override it, but the worker still needs a non-empty model string.

## TUI/runtime integration later

First implementation can use settings-only model selection. After that:

- Footer/status segment showing current model + reasoning.
- `/model provider/model` command.
- `/reasoning low|medium|high|xhigh|off` command.
- Model selector list from `ConfiguredModelRegistry::getAvailableModels()`.
- Runtime events:
  - `model_changed`
  - `reasoning_changed`

## Validation plan

Targeted tests:

- Config parsing/merge for `ai` section.
- Secret resolver (`env:VAR`, null, plain values).
- `AiModelRef` parsing/formatting.
- Generic catalog supports only configured models.
- Provider factory builds deepseek and generic providers with configured names.
- Model selection priority: explicit > session > settings > first available.
- Reasoning selection priority mirrors model selection.
- Model resolver returns raw model name, provider ID, and reasoning options.
- Routing subscriber calls `ModelRoutingEvent::setProvider()` when provider is present.

Integration tests:

- Use fake/provider stubs to verify `LlmPlatformAdapter` receives configured model/options.
- Existing `PlatformIntegrationTest` should continue to pass.

Full validation:

```bash
castor check
```

## Rollout phases

### Phase 1 — Config and model registry

- Add typed `ai` config DTOs.
- Add settings defaults/docs examples.
- Add `AiModelRef`, secret resolver, configured model registry.
- Add generic catalog presets for `llama_cpp` and `z_ai`.

### Phase 2 — Symfony AI provider/platform wiring

- Require `symfony/ai-deep-seek-platform` and `symfony/ai-generic-platform` after 0.9 upgrade.
- Build provider registry and configured platform factory.
- Alias Symfony platform and AgentCore platform adapter in DI.
- Bind fallback default model.

### Phase 3 — Per-turn model/reasoning

- Implement session/home settings persistence for model and reasoning.
- Implement production `ModelResolverInterface`.
- Extend `ResolvedModel` with provider ID.
- Update routing subscriber to set explicit provider.

### Phase 4 — User controls

- CLI options for initial `--model` and `--reasoning`.
- TUI commands `/model` and `/reasoning`.
- Footer/status display.
- Optional model picker UI.

## Open questions

- Exact z.ai base URL, model names, and reasoning/tool-call capability matrix.
- Exact llama.cpp server capabilities in the intended deployment.
- Whether reasoning levels should be global only or optionally model/provider-scoped.
- Whether home settings writes should preserve comments or use a separate machine-managed file.
- Whether project settings should be allowed to override `default_model`, or only provider/model availability.
