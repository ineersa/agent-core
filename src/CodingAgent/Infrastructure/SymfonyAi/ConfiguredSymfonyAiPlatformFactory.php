<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Infrastructure\SymfonyAi;

use Symfony\AI\Platform\ModelRouter\CatalogBasedModelRouter;
use Symfony\AI\Platform\Platform;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Creates the Symfony AI Platform from Hatfield settings.
 *
 * Default construction wires every enabled provider into one Platform.
 * Extension-agent calls with an explicit HTTP budget can request a
 * single-provider Platform that applies those budgets via
 * {@see SymfonyAiProviderFactory::createProvider()}.
 *
 * The returned Platform is used as the concrete implementation behind
 * {@see Symfony\AI\Platform\PlatformInterface} in the DI container.
 */
final class ConfiguredSymfonyAiPlatformFactory
{
    public function __construct(
        private readonly SymfonyAiProviderFactory $providerFactory,
        private readonly EventDispatcherInterface $eventDispatcher,
    ) {
    }

    /**
     * Create the multi-provider Platform from the current Hatfield config.
     *
     * @throws \RuntimeException when no providers are configured
     */
    public function createPlatform(): Platform
    {
        $providers = $this->providerFactory->createProviders();

        if ([] === $providers) {
            throw new \RuntimeException('No AI providers are enabled. Check your .hatfield/settings.yaml ai section.');
        }

        return new Platform(
            providers: array_values($providers),
            modelRouter: new CatalogBasedModelRouter(),
            eventDispatcher: $this->eventDispatcher,
        );
    }

    /**
     * Create a single-provider Platform with explicit HTTP timeout budgets.
     *
     * Both idle timeout and total max_duration are set to $budgetSeconds for
     * every HTTP request made through this Platform.
     *
     * @throws \RuntimeException when the provider is missing or disabled
     */
    public function createPlatformForProvider(string $providerId, int $budgetSeconds): Platform
    {
        if ($budgetSeconds < 1) {
            throw new \InvalidArgumentException('Platform HTTP budget must be a positive integer.');
        }

        $provider = $this->providerFactory->createProvider(
            $providerId,
            $budgetSeconds,
        );

        return new Platform(
            providers: [$provider],
            modelRouter: new CatalogBasedModelRouter(),
            eventDispatcher: $this->eventDispatcher,
        );
    }
}
