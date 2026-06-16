<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Contract;

use Ineersa\AgentCore\Domain\Model\ProviderCompatibility;

/**
 * Resolves provider compatibility configuration for a given model name.
 *
 * Implementations (e.g. in CodingAgent) look up the model definition from
 * the Hatfield config/catalog and translate it into a
 * {@see ProviderCompatibility} value object.
 *
 * The resolver is called by
 * {@see \Ineersa\AgentCore\Infrastructure\SymfonyAi\ProviderCompatibilityRequestShaper}
 * AFTER normal {@see Hook\BeforeProviderRequestHookInterface}
 * hooks have run, using the final effective model name.
 */
interface ProviderCompatibilityResolverInterface
{
    /**
     * Resolve compatibility for the given effective model name.
     *
     * @param string $model the raw model name as sent to the Symfony AI platform
     *                      (after any hook model rewrites)
     */
    public function resolve(string $model): ProviderCompatibility;
}
