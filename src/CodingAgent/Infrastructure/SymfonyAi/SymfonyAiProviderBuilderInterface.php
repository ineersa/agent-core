<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Infrastructure\SymfonyAi;

use Ineersa\CodingAgent\Config\Ai\AiProviderConfig;
use Symfony\AI\Platform\ProviderInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Strategy for building a Symfony AI {@see ProviderInterface} for a specific Hatfield provider type.
 *
 * {@see SymfonyAiProviderFactory} iterates registered builders and delegates to the first whose
 * {@see supports()} matches; otherwise it falls back to the generic chat-completions path.
 */
interface SymfonyAiProviderBuilderInterface
{
    public function supports(AiProviderConfig $provider): bool;

    public function build(AiProviderConfig $provider, HttpClientInterface $httpClient): ProviderInterface;
}
