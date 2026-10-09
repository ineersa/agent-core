<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Infrastructure\SymfonyAi\ChatGPT;

use Ineersa\CodingAgent\Config\Ai\AiProviderConfig;
use Ineersa\CodingAgent\Infrastructure\SymfonyAi\ProjectedSymfonyModelCatalog;
use Ineersa\CodingAgent\Infrastructure\SymfonyAi\SymfonyAiProviderBuilderInterface;
use Symfony\AI\Platform\Bridge\OpenAIChatGPT\Auth\OAuthService;
use Symfony\AI\Platform\Bridge\OpenAIChatGPT\Factory;
use Symfony\AI\Platform\Bridge\OpenResponses\ResponsesModel;
use Symfony\AI\Platform\ProviderInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final readonly class ChatGPTSymfonyAiProviderBuilder implements SymfonyAiProviderBuilderInterface
{
    public function __construct(
        private OAuthService $auth,
        private EventDispatcherInterface $eventDispatcher,
    ) {
    }

    public function supports(AiProviderConfig $provider): bool
    {
        return 'chatgpt' === $provider->type;
    }

    public function build(AiProviderConfig $provider, HttpClientInterface $httpClient): ProviderInterface
    {
        // Authentication is deferred until inference, so a fresh installation can
        // boot its console and complete login before any grant exists.
        return Factory::createProvider(
            auth: $this->auth,
            httpClient: $httpClient,
            modelCatalog: new ProjectedSymfonyModelCatalog(
                hatfieldModels: $provider->models,
                modelClass: ResponsesModel::class,
                providerId: $provider->id,
            ),
            eventDispatcher: $this->eventDispatcher,
            name: $provider->id,
        );
    }
}
