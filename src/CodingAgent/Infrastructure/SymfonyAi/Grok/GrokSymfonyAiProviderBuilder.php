<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Infrastructure\SymfonyAi\Grok;

use Ineersa\CodingAgent\Auth\GrokAuthStorage;
use Ineersa\CodingAgent\Auth\GrokOAuthConfig;
use Ineersa\CodingAgent\Auth\GrokOAuthService;
use Ineersa\CodingAgent\Config\Ai\AiProviderConfig;
use Ineersa\CodingAgent\Infrastructure\SymfonyAi\ProjectedSymfonyModelCatalog;
use Ineersa\CodingAgent\Infrastructure\SymfonyAi\SymfonyAiProviderBuilderInterface;
use Ineersa\Platform\Bridge\Grok\GrokModelClient;
use Psr\Log\LoggerInterface;
use Symfony\AI\Platform\Bridge\OpenResponses\Contract\OpenResponsesContract;
use Symfony\AI\Platform\Bridge\OpenResponses\ResponsesModel;
use Symfony\AI\Platform\Bridge\OpenResponses\ResultConverter;
use Symfony\AI\Platform\Provider;
use Symfony\AI\Platform\ProviderInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class GrokSymfonyAiProviderBuilder implements SymfonyAiProviderBuilderInterface
{
    public function __construct(
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly GrokAuthStorage $grokAuth,
        private readonly GrokOAuthService $grokOAuth,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    public function supports(AiProviderConfig $provider): bool
    {
        return 'grok' === $provider->type;
    }

    public function build(AiProviderConfig $provider, HttpClientInterface $httpClient): ProviderInterface
    {
        $projectedCatalog = new ProjectedSymfonyModelCatalog(
            hatfieldModels: $provider->models,
            modelClass: ResponsesModel::class,
            providerId: $provider->id,
        );

        $authKey = $this->resolveAuthKey($provider);

        $record = $this->grokAuth->loadCredentials($authKey);
        if (null === $record) {
            $hint = GrokOAuthConfig::authCommandHint();
            throw new \RuntimeException(\sprintf('Grok CLI provider "%s" requires stored OAuth credentials. Run: %s', $provider->id, $hint));
        }

        // base WITHOUT /v1 + path WITH /v1 — do not double it.
        $baseUrl = '' !== $provider->baseUrl ? $provider->baseUrl : 'https://cli-chat-proxy.grok.com';
        $path = $provider->completionsPath ?? '/v1/responses';

        $oAuth = $this->grokOAuth;
        $accessTokenRefresher = static function () use ($oAuth, $authKey): string {
            return $oAuth->refreshCredentials($authKey)->access;
        };

        $modelClient = new GrokModelClient(
            httpClient: $httpClient,
            baseUrl: $baseUrl,
            apiKey: $record->access,
            path: $path,
            logger: $this->logger,
            accessTokenRefresher: $accessTokenRefresher,
        );

        return new Provider(
            $provider->id,
            [$modelClient],
            [new ResultConverter()],
            $projectedCatalog,
            OpenResponsesContract::create(),
            $this->eventDispatcher,
        );
    }

    /**
     * @return non-empty-string
     */
    private function resolveAuthKey(AiProviderConfig $provider): string
    {
        $authKey = $provider->authKey;

        if (null === $authKey || '' === trim($authKey)) {
            return GrokOAuthConfig::PROVIDER_KEY;
        }

        return trim($authKey);
    }
}
