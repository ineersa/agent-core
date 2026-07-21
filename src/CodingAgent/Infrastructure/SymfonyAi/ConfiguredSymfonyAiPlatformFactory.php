<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Infrastructure\SymfonyAi;

use Symfony\AI\Platform\ModelRouter\CatalogBasedModelRouter;
use Symfony\AI\Platform\Platform;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Creates the single multi-provider Symfony AI Platform from Hatfield settings.
 *
 * This factory wires together all enabled Hatfield providers into one
 * Platform instance, including the Symfony event dispatcher so that
 * {@see ModelRoutingEvent}, {@see InvocationEvent}, and {@see ResultEvent}
 * fire correctly.
 *
 * The returned Platform is used as the concrete implementation behind
 * {@see Symfony\AI\Platform\PlatformInterface} in the DI container.
 * Callers that must avoid eager provider construction should depend on
 * {@see SymfonyPlatformFactoryInterface} and invoke createPlatform() only
 * when a model call is actually required.
 */
final class ConfiguredSymfonyAiPlatformFactory implements SymfonyPlatformFactoryInterface
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
    public function createPlatform(): PlatformInterface
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
}
