<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Infrastructure\SymfonyAi;

use Symfony\AI\Platform\ModelRouter\CatalogBasedModelRouter;
use Symfony\AI\Platform\Platform;
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
 */
final class ConfiguredSymfonyAiPlatformFactory
{
    public function __construct(
        private readonly SymfonyAiProviderFactory $providerFactory,
        private readonly EventDispatcherInterface $eventDispatcher,
    ) {
    }

    /**
     * Create the multi-provider Platform for a given project directory.
     *
     * @param string $projectCwd Project working directory (default: process cwd)
     *
     * @throws \RuntimeException when no providers are configured
     */
    public function createPlatform(string $projectCwd = ''): Platform
    {
        $providers = $this->providerFactory->createProviders($projectCwd);

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
