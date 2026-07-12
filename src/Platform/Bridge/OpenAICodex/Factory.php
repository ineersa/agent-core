<?php

declare(strict_types=1);

namespace Symfony\AI\Platform\Bridge\OpenAICodex;

use Psr\Log\LoggerInterface;
use Symfony\AI\Platform\Bridge\OpenAICodex\Contract\CodexContract;
use Symfony\AI\Platform\Contract;
use Symfony\AI\Platform\ModelCatalog\ModelCatalogInterface;
use Symfony\AI\Platform\ModelRouter\CatalogBasedModelRouter;
use Symfony\AI\Platform\ModelRouterInterface;
use Symfony\AI\Platform\Platform;
use Symfony\AI\Platform\Provider;
use Symfony\AI\Platform\ProviderInterface;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class Factory
{
    /**
     * @param non-empty-string $name
     */
    public static function createProvider(
        string $baseUrl = 'https://chatgpt.com/backend-api',
        #[\SensitiveParameter] string $accessToken = '',
        string $accountId = '',
        ?HttpClientInterface $httpClient = null,
        ModelCatalogInterface $modelCatalog = new CodexModelCatalog(),
        ?Contract $contract = null,
        ?EventDispatcherInterface $eventDispatcher = null,
        string $responsesPath = '/codex/responses',
        string $originator = 'hatfield',
        string $name = 'openai-codex',
        ?LoggerInterface $logger = null,
        ?\Closure $accessTokenRefresher = null,
    ): ProviderInterface {
        // Use the raw HttpClientInterface directly — no EventSourceHttpClient
        // wrapping. The CodexSseStream (inside CodexModelClient) handles SSE
        // parsing independently of content-type headers.
        $httpClient ??= HttpClient::create();

        return new Provider(
            $name,
            [new CodexModelClient($httpClient, $baseUrl, $accessToken, $accountId, $responsesPath, $originator, $logger, $accessTokenRefresher)],
            [new ResultConverter()],
            $modelCatalog,
            $contract ?? CodexContract::create(),
            $eventDispatcher,
        );
    }

    /**
     * @param non-empty-string $name
     */
    public static function createPlatform(
        string $baseUrl = 'https://chatgpt.com/backend-api',
        #[\SensitiveParameter] string $accessToken = '',
        string $accountId = '',
        ?HttpClientInterface $httpClient = null,
        ModelCatalogInterface $modelCatalog = new CodexModelCatalog(),
        ?Contract $contract = null,
        ?EventDispatcherInterface $eventDispatcher = null,
        string $responsesPath = '/codex/responses',
        string $originator = 'hatfield',
        string $name = 'openai-codex',
        ?ModelRouterInterface $modelRouter = null,
        ?LoggerInterface $logger = null,
        ?\Closure $accessTokenRefresher = null,
    ): Platform {
        return new Platform(
            [self::createProvider($baseUrl, $accessToken, $accountId, $httpClient, $modelCatalog, $contract, $eventDispatcher, $responsesPath, $originator, $name, $logger, $accessTokenRefresher)],
            $modelRouter ?? new CatalogBasedModelRouter(),
            $eventDispatcher,
        );
    }
}
