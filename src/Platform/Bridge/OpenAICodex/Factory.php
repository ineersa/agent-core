<?php

declare(strict_types=1);

namespace Symfony\AI\Platform\Bridge\OpenAICodex;

use Symfony\AI\Platform\Contract;
use Symfony\AI\Platform\ModelCatalog\ModelCatalogInterface;
use Symfony\AI\Platform\ModelRouter\CatalogBasedModelRouter;
use Symfony\AI\Platform\ModelRouterInterface;
use Symfony\AI\Platform\Platform;
use Symfony\AI\Platform\Provider;
use Symfony\AI\Platform\ProviderInterface;
use Symfony\Component\HttpClient\EventSourceHttpClient;
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
    ): ProviderInterface {
        $httpClient = $httpClient instanceof EventSourceHttpClient
            ? $httpClient
            : new EventSourceHttpClient($httpClient);

        return new Provider(
            $name,
            [new CodexModelClient($httpClient, $baseUrl, $accessToken, $accountId, $responsesPath, $originator)],
            [new ResultConverter()],
            $modelCatalog,
            $contract ?? Contract::create(),
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
    ): Platform {
        return new Platform(
            [self::createProvider($baseUrl, $accessToken, $accountId, $httpClient, $modelCatalog, $contract, $eventDispatcher, $responsesPath, $originator, $name)],
            $modelRouter ?? new CatalogBasedModelRouter(),
            $eventDispatcher,
        );
    }
}
