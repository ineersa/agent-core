<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Infrastructure\SymfonyAi;

use Ineersa\CodingAgent\Auth\CodexAuthStorage;
use Ineersa\CodingAgent\Auth\CodexOAuthConfig;
use Ineersa\CodingAgent\Config\Ai\AiProviderConfig;
use Ineersa\CodingAgent\Config\AppConfig;
use Ineersa\Platform\Bridge\Generic\DurableResultConverter;
use Psr\Log\LoggerInterface;
use Symfony\AI\Platform\Bridge\Generic\Completions\ModelClient as GenericCompletionsModelClient;
use Symfony\AI\Platform\Bridge\Generic\CompletionsModel;
use Symfony\AI\Platform\Bridge\Generic\Embeddings\ModelClient as GenericEmbeddingsModelClient;
use Symfony\AI\Platform\Bridge\Generic\Embeddings\ResultConverter as GenericEmbeddingsResultConverter;
use Symfony\AI\Platform\Bridge\OpenAICodex\CodexModel;
use Symfony\AI\Platform\Bridge\OpenAICodex\Factory as OpenAICodexFactory;
use Symfony\AI\Platform\Provider;
use Symfony\AI\Platform\ProviderInterface;
use Symfony\Component\HttpClient\EventSourceHttpClient;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

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
        private readonly ?CodexAuthStorage $codexAuth = null,
        private readonly ?LoggerInterface $logger = null,
        private readonly ?HttpClientInterface $httpClient = null,
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
     * Return a configured HttpClient for outgoing LLM requests.
     *
     * When an HttpClient is explicitly injected (e.g. test environment
     * via services_test.yaml, or by a test replay factory), use it
     * directly.  Otherwise create a default one with a permissive
     * fallback timeout so a stuck generation endpoint cannot hang the
     * agent indefinitely.
     */
    private function getHttpClient(): HttpClientInterface
    {
        if (null !== $this->httpClient) {
            return $this->httpClient;
        }

        // No explicit timeout configured — use a generous default
        // (30s) that still prevents infinite hangs.  For the local
        // llama_cpp_test/test smoke model this is still too long, but
        // Castor-level preflight (check_llm_generation_ready) catches
        // stuck generation in <5s before tests start.
        $timeout = (int) ($_ENV['HATFIELD_LLM_HTTP_TIMEOUT'] ?? 30);

        return HttpClient::create(['timeout' => $timeout]);
    }

    /**
     * Build a single provider from Hatfield config + a projected model catalog.
     */
    private function buildProvider(AiProviderConfig $provider, ProjectedSymfonyModelCatalog $projectedCatalog): ProviderInterface
    {
        if ('codex' === $provider->type) {
            return $this->buildCodexProvider($provider, $projectedCatalog);
        }

        return $this->buildGenericCompletionsProvider($provider, $projectedCatalog);
    }

    private function buildCodexProvider(AiProviderConfig $provider, ProjectedSymfonyModelCatalog $projectedCatalog): ProviderInterface
    {
        $authKey = $this->resolveCodexAuthKey($provider);

        if (null === $this->codexAuth) {
            $hint = CodexOAuthConfig::authCommandHintForProviderKey($authKey);
            throw new \RuntimeException(\sprintf('OpenAI Codex provider "%s" requires stored OAuth credentials. Run: %s', $provider->id, $hint));
        }

        $record = $this->codexAuth->loadCredentials($authKey);
        if (null === $record) {
            $hint = CodexOAuthConfig::authCommandHintForProviderKey($authKey);
            throw new \RuntimeException(\sprintf('OpenAI Codex provider "%s" requires stored OAuth credentials. Run: %s', $provider->id, $hint));
        }

        // Use the configured baseUrl falling back to the OpenAICodex factory default,
        // so a YAML provider with an empty base_url does not silently break the bridge.
        $baseUrl = '' !== $provider->baseUrl ? $provider->baseUrl : 'https://chatgpt.com/backend-api';

        return OpenAICodexFactory::createProvider(
            baseUrl: $baseUrl,
            accessToken: $record->access,
            accountId: $record->accountId,
            httpClient: $this->getHttpClient(),
            modelCatalog: $projectedCatalog,
            contract: null,
            eventDispatcher: $this->eventDispatcher,
            responsesPath: $provider->completionsPath ?? '/codex/responses',
            name: $provider->id,
            logger: $this->logger,
        );
    }

    /**
     * Resolve the auth storage key for a Codex provider config.
     *
     * Returns the default PROVIDER_KEY when authKey is null/empty/whitespace,
     * returns valid profile keys as-is, and throws for malformed keys
     * that cannot be created through auth:codex --auth-profile=<name>.
     *
     * @return non-empty-string
     *
     * @throws \RuntimeException when authKey is invalid
     */
    private function resolveCodexAuthKey(AiProviderConfig $provider): string
    {
        $authKey = $provider->authKey;

        // Default: null/empty/whitespace uses the default provider key
        if (null === $authKey || '' === trim($authKey)) {
            return CodexOAuthConfig::PROVIDER_KEY;
        }

        // Explicit default key is always valid
        if (CodexOAuthConfig::PROVIDER_KEY === $authKey) {
            return $authKey;
        }

        // Profile-generated keys like 'openai-codex-work' must have a valid profile suffix
        $profile = CodexOAuthConfig::profileFromProviderKey($authKey);
        if (null !== $profile) {
            // Valid profile-generated key
            return $authKey;
        }

        // Anything else is invalid: openai-codex- with no suffix, my-custom-key, weird chars
        throw new \RuntimeException(\sprintf('OpenAI Codex provider "%s" has an invalid auth_key "%s". Use "openai-codex" for the default account or run bin/console auth:codex --auth-profile=<name> to create an account under "openai-codex-<name>".', $provider->id, $authKey));
    }

    /**
     * Build a generic chat-completions provider with durable streaming tool-call conversion.
     *
     * Replaces the vendor GenericFactory default ResultConverter with
     * {@see DurableResultConverter}, which uses dual-map (stream index +
     * tool-call id) tracking for robust sparse/out-of-order tool-call chunk
     * handling.  The HTTP client, embedding support, and Provider wiring
     * are identical to what GenericFactory creates.
     */
    private function buildGenericCompletionsProvider(AiProviderConfig $provider, ProjectedSymfonyModelCatalog $projectedCatalog): ProviderInterface
    {
        $httpClient = $this->getHttpClient();
        $httpClient = $httpClient instanceof EventSourceHttpClient ? $httpClient : new EventSourceHttpClient($httpClient);

        $modelClients = [];
        $resultConverters = [];

        if ($provider->supportsCompletions) {
            $completionsPath = $provider->completionsPath ?? '/v1/chat/completions';
            $modelClients[] = new GenericCompletionsModelClient(
                $httpClient,
                $provider->baseUrl,
                $this->resolveApiKey($provider->apiKey),
                $completionsPath,
            );
            $resultConverters[] = new DurableResultConverter();
        }

        if ($provider->supportsEmbeddings) {
            $embeddingsPath = $provider->embeddingsPath ?? '/v1/embeddings';
            $modelClients[] = new GenericEmbeddingsModelClient(
                $httpClient,
                $provider->baseUrl,
                $this->resolveApiKey($provider->apiKey),
                $embeddingsPath,
            );
            $resultConverters[] = new GenericEmbeddingsResultConverter();
        }

        return new Provider(
            $provider->id,
            $modelClients,
            $resultConverters,
            $projectedCatalog,
            null,  // contract — use Symfony AI default
            $this->eventDispatcher,
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
