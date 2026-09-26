<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Infrastructure\SymfonyAi\Codex;

use Ineersa\CodingAgent\Config\Ai\AiProviderConfig;
use Ineersa\CodingAgent\Infrastructure\SymfonyAi\ProjectedSymfonyModelCatalog;
use Ineersa\CodingAgent\Infrastructure\SymfonyAi\SymfonyAiProviderBuilderInterface;
use Psr\Log\LoggerInterface;
use Symfony\AI\Platform\Bridge\OpenAICodex\Auth\CodexAuthFileStore;
use Symfony\AI\Platform\Bridge\OpenAICodex\Auth\CodexOAuthService;
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
        private readonly CodexAuthFileStore $codexAuth,
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
            providerId: $provider->id,
        );

        $record = $this->codexAuth->loadCredentials();
        if (null === $record) {
            throw new \RuntimeException(\sprintf('OpenAI Codex provider "%s" requires stored OAuth credentials. Run: bin/console auth:codex', $provider->id));
        }

        // Use the configured baseUrl falling back to the OpenAICodex factory default,
        // so a YAML provider with an empty base_url does not silently break the bridge.
        $baseUrl = '' !== $provider->baseUrl ? $provider->baseUrl : 'https://chatgpt.com/backend-api';

        $oAuth = $this->codexOAuth;
        $accessTokenRefresher = static function () use ($oAuth): string {
            return $oAuth->refreshCredentials()->access;
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
            originator: 'hatfield',
            userAgent: 'hatfield',
            internalOptions: ['hatfield_run_id', 'hatfield_model_ref'],
        );
    }
}
