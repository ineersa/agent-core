<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Infrastructure\SymfonyAi;

use Ineersa\AgentCore\Contract\Model\ProviderRegistryInterface;
use Symfony\AI\Platform\ProviderInterface;

/**
 * Lazily-initialised registry of Symfony AI Platform providers keyed by Hatfield provider ID.
 *
 * Providers are built once from the current Hatfield config via
 * {@see SymfonyAiProviderFactory::createProviders()} and cached for the
 * lifetime of the request.
 *
 * Consumed by {@see ModelResolverRoutingSubscriber} to select a specific
 * provider when the resolved model includes a provider ID, allowing
 * the platform to skip catalog-based routing.
 */
final class SymfonyAiProviderRegistry implements ProviderRegistryInterface
{
    /** @var array<string, ProviderInterface>|null */
    private ?array $providers = null;

    public function __construct(
        private readonly SymfonyAiProviderFactory $providerFactory,
    ) {
    }

    /**
     * Get a provider by its Hatfield provider ID.
     */
    public function get(string $id): ?ProviderInterface
    {
        return $this->all()[$id] ?? null;
    }

    /**
     * Get all registered providers, keyed by provider ID.
     *
     * @return array<string, ProviderInterface>
     */
    public function all(): array
    {
        if (null === $this->providers) {
            $this->providers = $this->providerFactory->createProviders();
        }

        return $this->providers;
    }
}
