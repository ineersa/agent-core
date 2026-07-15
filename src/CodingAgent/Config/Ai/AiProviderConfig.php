<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Config\Ai;

/**
 * Provider-specific configuration from Hatfield AI settings.
 *
 * Each provider entry describes how to reach a backend (OpenAI, Anthropic,
 * llama.cpp, DeepSeek, z.ai, etc.) plus which models it exposes.
 */
final readonly class AiProviderConfig
{
    /**
     * @param string                           $id                           Provider ID used in model references (e.g. deepseek, llama_cpp, zai)
     * @param string                           $type                         Provider type (generic for OpenAI-completions-style)
     * @param bool                             $enabled                      Whether this provider is active
     * @param string                           $baseUrl                      Base URL for the provider's API
     * @param string                           $api                          API flavor (e.g. openai-completions)
     * @param string|null                      $apiKey                       API key (plain or env:VAR format; resolved by SecretResolver)
     * @param string|null                      $accountId                    Account ID for provider (e.g. Codex chatgpt-account-id header)
     * @param string|null                      $authKey                      Auth storage key for Codex OAuth credentials (defaults to 'openai-codex' when null)
     * @param string|null                      $completionsPath              Chat completions endpoint path (e.g. /chat/completions)
     * @param string|null                      $embeddingsPath               Embeddings endpoint path (e.g. /embeddings)
     * @param bool                             $supportsCompletions          Whether chat completions are supported
     * @param bool                             $supportsEmbeddings           Whether embeddings are supported
     * @param bool                             $supportsThinkingLevels       Whether reasoning-level cycling is meaningful for this provider
     * @param AiCompatibility|null             $compatibility                Provider-level compatibility metadata
     * @param string|null                      $transport                    Codex transport (websocket|websocket-cached|sse); null uses Codex default
     * @param int|null                         $websocketCacheIdleTtlSeconds Codex websocket-cached idle TTL (default 300)
     * @param int|null                         $websocketCacheMaxAgeSeconds  Codex websocket-cached max age (default 3300)
     * @param array<string, AiModelDefinition> $models                       Exposed models keyed by model name
     */
    public function __construct(
        public string $id,
        public string $type = 'generic',
        public bool $enabled = true,
        public string $baseUrl = '',
        public string $api = 'openai-completions',
        public ?string $apiKey = null,
        public ?string $accountId = null,
        public ?string $authKey = null,
        public ?string $completionsPath = null,
        public ?string $embeddingsPath = null,
        public bool $supportsCompletions = true,
        public bool $supportsEmbeddings = false,
        public bool $supportsThinkingLevels = true,
        public ?AiCompatibility $compatibility = null,
        public ?string $transport = null,
        public ?int $websocketCacheIdleTtlSeconds = null,
        public ?int $websocketCacheMaxAgeSeconds = null,
        public array $models = [],
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data, string $providerId): self
    {
        $models = [];
        if (isset($data['models']) && \is_array($data['models'])) {
            foreach ($data['models'] as $modelId => $modelData) {
                if (!\is_string($modelId) || '' === $modelId) {
                    throw new \RuntimeException(\sprintf('Provider "%s": model key must be a non-empty string in AI config.', $providerId));
                }
                $modelData = \is_array($modelData) ? $modelData : [];
                $models[$modelId] = AiModelDefinition::fromArray($modelData, $modelId);
            }
        }

        return new self(
            id: $providerId,
            type: (string) ($data['type'] ?? 'generic'),
            enabled: (bool) ($data['enabled'] ?? true),
            baseUrl: (string) ($data['base_url'] ?? ''),
            api: (string) ($data['api'] ?? 'openai-completions'),
            apiKey: isset($data['api_key']) ? (string) $data['api_key'] : null,
            accountId: isset($data['account_id']) ? (string) $data['account_id'] : null,
            authKey: isset($data['auth_key']) ? (string) $data['auth_key'] : null,
            completionsPath: isset($data['completions_path']) ? (string) $data['completions_path'] : null,
            embeddingsPath: isset($data['embeddings_path']) ? (string) $data['embeddings_path'] : null,
            supportsCompletions: (bool) ($data['supports_completions'] ?? true),
            supportsEmbeddings: (bool) ($data['supports_embeddings'] ?? false),
            supportsThinkingLevels: (bool) ($data['supports_thinking_levels'] ?? true),
            compatibility: isset($data['compatibility']) && \is_array($data['compatibility']) ? AiCompatibility::fromArray($data['compatibility']) : null,
            transport: isset($data['transport']) ? (string) $data['transport'] : null,
            websocketCacheIdleTtlSeconds: isset($data['websocket_cache_idle_ttl_seconds']) ? (int) $data['websocket_cache_idle_ttl_seconds'] : null,
            websocketCacheMaxAgeSeconds: isset($data['websocket_cache_max_age_seconds']) ? (int) $data['websocket_cache_max_age_seconds'] : null,
            models: $models,
        );
    }
}
