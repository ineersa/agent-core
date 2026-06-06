<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Infrastructure\SymfonyAi;

use Ineersa\CodingAgent\Config\Ai\AiProviderConfig;
use Ineersa\CodingAgent\Config\AppConfig;
use Symfony\AI\Platform\Bridge\Generic\CompletionsModel;
use Symfony\AI\Platform\Bridge\Generic\Factory as GenericFactory;
use Symfony\AI\Platform\Bridge\OpenAICodex\CodexModel;
use Symfony\AI\Platform\Bridge\OpenAICodex\Factory as OpenAICodexFactory;
use Symfony\AI\Platform\ProviderInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Creates Symfony AI Provider instances from Hatfield AI settings.
 *
 * Reads the merged Hatfield config from {@see AppConfig} (autowired DI
 * service built by {@see AppConfig::fromContainer}). For each enabled
 * provider in the Hatfield catalog it constructs a generic-chat-completions
 * Provider with a projected model catalog derived from Hatfield's rich
 * model definitions.
 *
 * This service is the bridge between Hatfield's user-facing model
 * config and Symfony AI Platform's provider model.
 */
class SymfonyAiProviderFactory
{
    public function __construct(
        private readonly AppConfig $appConfig,
        private readonly EventDispatcherInterface $eventDispatcher,
    ) {
    }

    /**
     * Create all enabled providers from the current Hatfield config.
     *
     * @return array<string, ProviderInterface> Providers keyed by Hatfield provider ID
     */
    public function createProviders(): array
    {
        $catalog = $this->appConfig->catalog;

        if (null === $catalog) {
            return [];
        }

        $providers = [];

        foreach ($catalog->config()->providers as $provider) {
            if (!$provider->enabled) {
                continue;
            }

            $projectedCatalog = new ProjectedSymfonyModelCatalog(
                hatfieldModels: $provider->models,
                modelClass: 'codex' === $provider->type
                    ? CodexModel::class
                    : CompletionsModel::class,
            );

            $providers[$provider->id] = $this->buildProvider($provider, $projectedCatalog);
        }

        return $providers;
    }

    /**
     * Build a single provider from Hatfield config + a projected model catalog.
     */
    private function buildProvider(AiProviderConfig $provider, ProjectedSymfonyModelCatalog $projectedCatalog): ProviderInterface
    {
        if ('codex' === $provider->type) {
            return $this->buildCodexProvider($provider, $projectedCatalog);
        }

        return GenericFactory::createProvider(
            baseUrl: $provider->baseUrl,
            apiKey: $this->resolveApiKey($provider->apiKey),
            httpClient: null,
            modelCatalog: $projectedCatalog,
            contract: null,
            eventDispatcher: $this->eventDispatcher,
            supportsCompletions: $provider->supportsCompletions,
            supportsEmbeddings: $provider->supportsEmbeddings,
            completionsPath: $provider->completionsPath ?? '/v1/chat/completions',
            embeddingsPath: $provider->embeddingsPath ?? '/v1/embeddings',
            name: $provider->id,
        );
    }

    private function buildCodexProvider(AiProviderConfig $provider, ProjectedSymfonyModelCatalog $projectedCatalog): ProviderInterface
    {
        $apiKey = $this->resolveApiKey($provider->apiKey);

        if (null === $apiKey || '' === $apiKey) {
            throw new \RuntimeException(\sprintf('OpenAI Codex provider "%s" requires an api_key (OAuth access token). Run: bin/console auth:codex', $provider->id));
        }

        $accountId = $provider->accountId;
        if (null === $accountId || '' === $accountId) {
            throw new \RuntimeException(\sprintf('OpenAI Codex provider "%s" requires an account_id. Run: bin/console auth:codex', $provider->id));
        }

        return OpenAICodexFactory::createProvider(
            baseUrl: $provider->baseUrl,
            accessToken: $apiKey,
            accountId: $accountId,
            httpClient: null,
            modelCatalog: $projectedCatalog,
            contract: null,
            eventDispatcher: $this->eventDispatcher,
            responsesPath: $provider->completionsPath ?? '/codex/responses',
            name: $provider->id,
        );
    }

    /**
     * Resolve an API key value to its real string.
     *
     * Supports two formats:
     *  - Plain key: returned as-is.
     *  - env:VAR: resolved via getenv('VAR').
     *  - null: passed through.
     */
    private function resolveApiKey(?string $apiKey): ?string
    {
        if (null === $apiKey) {
            return null;
        }

        if (str_starts_with($apiKey, 'env:')) {
            $var = substr($apiKey, 4);
            $value = getenv($var);

            return false !== $value ? $value : null;
        }

        return $apiKey;
    }
}
