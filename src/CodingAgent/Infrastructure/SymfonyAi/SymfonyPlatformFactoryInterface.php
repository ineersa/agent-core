<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Infrastructure\SymfonyAi;

use Symfony\AI\Platform\PlatformInterface;

/**
 * Lazy Symfony AI Platform factory for callers that must not construct
 * providers during container boot (for example Extension API model calls).
 *
 * @internal
 */
interface SymfonyPlatformFactoryInterface
{
    /**
     * Create the multi-provider Platform from the current Hatfield config.
     *
     * @throws \RuntimeException when no providers are configured
     */
    public function createPlatform(): PlatformInterface;
}
