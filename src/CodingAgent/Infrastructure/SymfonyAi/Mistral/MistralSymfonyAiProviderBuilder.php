<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Infrastructure\SymfonyAi\Mistral;

use Ineersa\CodingAgent\Config\Ai\AiProviderConfig;
use Ineersa\CodingAgent\Infrastructure\SymfonyAi\ProjectedSymfonyModelCatalog;
use Ineersa\CodingAgent\Infrastructure\SymfonyAi\SymfonyAiProviderBuilderInterface;
use Symfony\AI\Platform\Bridge\Mistral\Factory;
use Symfony\AI\Platform\Bridge\Mistral\Mistral;
use Symfony\AI\Platform\ProviderInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final readonly class MistralSymfonyAiProviderBuilder implements SymfonyAiProviderBuilderInterface
{
    public function __construct(private EventDispatcherInterface $eventDispatcher)
    {
    }

    public function supports(AiProviderConfig $provider): bool
    {
        return 'mistral' === $provider->type;
    }

    public function build(AiProviderConfig $provider, HttpClientInterface $httpClient): ProviderInterface
    {
        $apiKey = $provider->apiKey ?? '';
        if (str_starts_with($apiKey, 'env:')) {
            $resolved = getenv(substr($apiKey, 4));
            $apiKey = false === $resolved ? '' : $resolved;
        }

        // The native contract replays thinking as content chunks, not the
        // OpenAI reasoning_content field that Mistral rejects.
        return Factory::createProvider(
            apiKey: $apiKey,
            httpClient: $httpClient,
            modelCatalog: new ProjectedSymfonyModelCatalog($provider->models, Mistral::class, $provider->id),
            eventDispatcher: $this->eventDispatcher,
            name: $provider->id,
            baseUrl: $provider->baseUrl,
        );
    }
}
