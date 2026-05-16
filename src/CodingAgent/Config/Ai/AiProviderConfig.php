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
     * @param string                         $id                Provider ID used in model refs (e.g. deepseek, llama_cpp, zai)
     * @param string                         $type              Provider type (generic for OpenAI-completions-style)
     * @param bool                           $enabled           Whether this provider is active
     * @param string                         $baseUrl           Base URL for the provider's API
     * @param string                         $api               API flavor (e.g. openai-completions)
     * @param string|null                    $apiKey            API key (plain or env:VAR format; resolved by SecretResolver)
     * @param string|null                    $completionsPath   Chat completions endpoint path (e.g. /chat/completions)
     * @param string|null                    $embeddingsPath    Embeddings endpoint path (e.g. /embeddings)
     * @param bool                           $supportsCompletions Whether chat completions are supported
     * @param bool                           $supportsEmbeddings  Whether embeddings are supported
     * @param AiCompat|null                  $compat            Provider-level compat metadata
     * @param array<string, AiModelDefinition> $models         Exposed models keyed by model name
     */
    public function __construct(
        public string $id,
        public string $type = 'generic',
        public bool $enabled = true,
        public string $baseUrl = '',
        public string $api = 'openai-completions',
        public ?string $apiKey = null,
        public ?string $completionsPath = null,
        public ?string $embeddingsPath = null,
        public bool $supportsCompletions = true,
        public bool $supportsEmbeddings = false,
        public ?AiCompat $compat = null,
        public array $models = [],
    ) {
    }

    /**
     * @param array<string, mixed> $data
     * @param string $providerId
     */
    public static function fromArray(array $data, string $providerId): self
    {
        $models = [];
        if (isset($data['models']) && \is_array($data['models'])) {
            foreach ($data['models'] as $modelId => $modelData) {
                if (!\is_string($modelId) || '' === $modelId) {
                    throw new \RuntimeException(
                        \sprintf('Provider "%s": model key must be a non-empty string in AI config.', $providerId),
                    );
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
            completionsPath: isset($data['completions_path']) ? (string) $data['completions_path'] : null,
            embeddingsPath: isset($data['embeddings_path']) ? (string) $data['embeddings_path'] : null,
            supportsCompletions: (bool) ($data['supports_completions'] ?? true),
            supportsEmbeddings: (bool) ($data['supports_embeddings'] ?? false),
            compat: isset($data['compat']) && \is_array($data['compat']) ? AiCompat::fromArray($data['compat']) : null,
            models: $models,
        );
    }
}
