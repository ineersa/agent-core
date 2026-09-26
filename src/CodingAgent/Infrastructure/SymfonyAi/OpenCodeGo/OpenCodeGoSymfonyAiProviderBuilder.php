<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Infrastructure\SymfonyAi\OpenCodeGo;

use Ineersa\CodingAgent\Config\Ai\AiProviderConfig;
use Ineersa\CodingAgent\Infrastructure\SymfonyAi\Http\OpenCodeSessionHttpClient;
use Ineersa\CodingAgent\Infrastructure\SymfonyAi\ProjectedSymfonyModelCatalog;
use Ineersa\CodingAgent\Infrastructure\SymfonyAi\SymfonyAiProviderBuilderInterface;
use Ineersa\Platform\Bridge\Generic\DurableResultConverter;
use Psr\Log\LoggerInterface;
use Symfony\AI\Platform\Bridge\Generic\Completions\ModelClient as CompletionsModelClient;
use Symfony\AI\Platform\Bridge\Generic\CompletionsModel;
use Symfony\AI\Platform\Bridge\OpenResponses\Factory as OpenResponsesFactory;
use Symfony\AI\Platform\Bridge\OpenResponses\ResponsesModel;
use Symfony\AI\Platform\Provider;
use Symfony\AI\Platform\ProviderInterface;
use Symfony\Component\HttpClient\EventSourceHttpClient;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final readonly class OpenCodeGoSymfonyAiProviderBuilder implements SymfonyAiProviderBuilderInterface
{
    private const string RESPONSES_MODEL = 'muse-spark-1.3-contributor';

    public function __construct(
        private EventDispatcherInterface $eventDispatcher,
        private LoggerInterface $logger,
    ) {
    }

    public function supports(AiProviderConfig $provider): bool
    {
        return 'opencode-go' === $provider->type;
    }

    public function build(AiProviderConfig $provider, HttpClientInterface $httpClient): ProviderInterface
    {
        $apiKey = $provider->apiKey;
        $missingKeyEnv = null;
        if (null !== $apiKey && str_starts_with($apiKey, 'env:')) {
            $envName = substr($apiKey, 4);
            $resolved = getenv($envName);
            $apiKey = false === $resolved || '' === $resolved ? null : $resolved;
            $missingKeyEnv = null === $apiKey ? $envName : null;
        }

        $httpClient = new OpenCodeSessionHttpClient($httpClient, $missingKeyEnv);
        $completionsModels = $provider->models;
        $responsesModels = [];
        if (isset($completionsModels[self::RESPONSES_MODEL])) {
            $responsesModels[self::RESPONSES_MODEL] = $completionsModels[self::RESPONSES_MODEL];
            unset($completionsModels[self::RESPONSES_MODEL]);
        }

        $completions = new Provider(
            $provider->id,
            [new CompletionsModelClient(
                new EventSourceHttpClient($httpClient),
                $provider->baseUrl,
                $apiKey,
                $provider->completionsPath ?? '/chat/completions',
            )],
            [new DurableResultConverter(logger: $this->logger)],
            new ProjectedSymfonyModelCatalog($completionsModels, CompletionsModel::class, $provider->id),
            null,
            $this->eventDispatcher,
        );
        $responses = OpenResponsesFactory::createProvider(
            baseUrl: $provider->baseUrl,
            apiKey: $apiKey,
            httpClient: $httpClient,
            modelCatalog: new ProjectedSymfonyModelCatalog($responsesModels, ResponsesModel::class, $provider->id),
            eventDispatcher: $this->eventDispatcher,
            responsesPath: '/responses',
            name: $provider->id,
        );

        return new OpenCodeGoProvider($provider->id, $completions, $responses);
    }
}
