<?php

declare(strict_types=1);

namespace Symfony\AI\Platform\Bridge\OpenAICodex;

use Psr\Log\LoggerInterface;
use Symfony\AI\Platform\Bridge\OpenAICodex\Contract\CodexContract;
use Symfony\AI\Platform\Contract;
use Symfony\AI\Platform\ModelCatalog\ModelCatalogInterface;
use Symfony\AI\Platform\ModelClientInterface;
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
        CodexTransportEnum $transport = CodexTransportEnum::Websocket,
        ?CodexWebSocketConnectorInterface $websocketConnector = null,
    ): ProviderInterface {
        $httpClient ??= HttpClient::create();
        $requestBodyFactory = new CodexRequestBodyFactory();

        $modelClient = self::createModelClient(
            $transport,
            $httpClient,
            $baseUrl,
            $accessToken,
            $accountId,
            $responsesPath,
            $originator,
            $logger,
            $accessTokenRefresher,
            $requestBodyFactory,
            $websocketConnector,
        );

        return new Provider(
            $name,
            [$modelClient],
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
        CodexTransportEnum $transport = CodexTransportEnum::Websocket,
        ?CodexWebSocketConnectorInterface $websocketConnector = null,
    ): Platform {
        return new Platform(
            [self::createProvider($baseUrl, $accessToken, $accountId, $httpClient, $modelCatalog, $contract, $eventDispatcher, $responsesPath, $originator, $name, $logger, $accessTokenRefresher, $transport, $websocketConnector)],
            $modelRouter ?? new CatalogBasedModelRouter(),
            $eventDispatcher,
        );
    }

    private static function createModelClient(
        CodexTransportEnum $transport,
        HttpClientInterface $httpClient,
        string $baseUrl,
        string $accessToken,
        string $accountId,
        string $responsesPath,
        string $originator,
        ?LoggerInterface $logger,
        ?\Closure $accessTokenRefresher,
        CodexRequestBodyFactory $requestBodyFactory,
        ?CodexWebSocketConnectorInterface $websocketConnector,
    ): ModelClientInterface {
        return match ($transport) {
            CodexTransportEnum::Sse => new CodexModelClient(
                $httpClient,
                $baseUrl,
                $accessToken,
                $accountId,
                $responsesPath,
                $originator,
                $logger,
                $accessTokenRefresher,
                $requestBodyFactory,
            ),
            CodexTransportEnum::Websocket => new CodexWebSocketModelClient(
                $websocketConnector ?? new AmpCodexWebSocketConnector(),
                new CodexWebSocketUrlResolver(),
                new CodexWebSocketHandshakeHeadersFactory(),
                $requestBodyFactory,
                $baseUrl,
                $accessToken,
                $accountId,
                $responsesPath,
                $originator,
                $logger,
                $accessTokenRefresher,
            ),
        };
    }
}
