<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Infrastructure\SymfonyAi\Codex;

use Ineersa\CodingAgent\Auth\CodexAuthStorage;
use Ineersa\CodingAgent\Auth\CodexOAuthConfig;
use Ineersa\CodingAgent\Auth\CodexOAuthService;
use Ineersa\CodingAgent\Config\Ai\AiProviderConfig;
use Ineersa\CodingAgent\Infrastructure\SymfonyAi\ProjectedSymfonyModelCatalog;
use Ineersa\CodingAgent\Infrastructure\SymfonyAi\SymfonyAiProviderBuilderInterface;
use Psr\Log\LoggerInterface;
use Symfony\AI\Platform\Bridge\OpenAICodex\CodexModel;
use Symfony\AI\Platform\Bridge\OpenAICodex\CodexTransportEnum;
use Symfony\AI\Platform\Bridge\OpenAICodex\CodexWebSocketCacheSettings;
use Symfony\AI\Platform\Bridge\OpenAICodex\CodexWebSocketConnectionCache;
use Symfony\AI\Platform\Bridge\OpenAICodex\Factory as OpenAICodexFactory;
use Symfony\AI\Platform\ProviderInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class CodexSymfonyAiProviderBuilder implements SymfonyAiProviderBuilderInterface
{
    public function __construct(
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly CodexAuthStorage $codexAuth,
        private readonly CodexOAuthService $codexOAuth,
        private readonly CodexWebSocketConnectionCache $codexWebSocketConnectionCache,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    public function supports(AiProviderConfig $provider): bool
    {
        return 'codex' === $provider->type;
    }

    public function build(AiProviderConfig $provider, HttpClientInterface $httpClient): ProviderInterface
    {
        $transport = CodexTransportEnum::fromNullableString($provider->transport);

        $projectedCatalog = new ProjectedSymfonyModelCatalog(
            hatfieldModels: $provider->models,
            modelClass: CodexModel::class,
        );

        $authKey = $this->resolveCodexAuthKey($provider);

        $record = $this->codexAuth->loadCredentials($authKey);
        if (null === $record) {
            $hint = CodexOAuthConfig::authCommandHintForProviderKey($authKey);
            throw new \RuntimeException(\sprintf('OpenAI Codex provider "%s" requires stored OAuth credentials. Run: %s', $provider->id, $hint));
        }

        // Use the configured baseUrl falling back to the OpenAICodex factory default,
        // so a YAML provider with an empty base_url does not silently break the bridge.
        $baseUrl = '' !== $provider->baseUrl ? $provider->baseUrl : 'https://chatgpt.com/backend-api';

        $oAuth = $this->codexOAuth;
        $accessTokenRefresher = static function () use ($oAuth, $authKey): string {
            return $oAuth->refreshCredentials($authKey)->access;
        };

        $cacheSettings = new CodexWebSocketCacheSettings(
            idleTtlSeconds: $provider->websocketCacheIdleTtlSeconds ?? CodexWebSocketCacheSettings::DEFAULT_IDLE_TTL_SECONDS,
            maxAgeSeconds: $provider->websocketCacheMaxAgeSeconds ?? CodexWebSocketCacheSettings::DEFAULT_MAX_AGE_SECONDS,
        );

        return OpenAICodexFactory::createProvider(
            baseUrl: $baseUrl,
            accessToken: $record->access,
            accountId: $record->accountId,
            httpClient: $httpClient,
            modelCatalog: $projectedCatalog,
            contract: null,
            eventDispatcher: $this->eventDispatcher,
            responsesPath: $provider->completionsPath ?? '/codex/responses',
            name: $provider->id,
            logger: $this->logger,
            accessTokenRefresher: $accessTokenRefresher,
            transport: $transport,
            websocketConnector: null,
            websocketConnectionCache: $this->codexWebSocketConnectionCache,
            websocketCacheSettings: $cacheSettings,
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
}
