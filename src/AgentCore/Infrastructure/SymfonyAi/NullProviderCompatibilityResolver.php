<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Infrastructure\SymfonyAi;

use Ineersa\AgentCore\Contract\ProviderCompatibilityResolverInterface;
use Ineersa\AgentCore\Domain\Model\ProviderCompatibility;

/**
 * Fallback resolver used when no real resolver is wired.
 *
 * Returns empty compatibility — no compat flags, standard OpenAI behavior.
 * This exists so {@see BeforeProviderRequestSubscriber} can compile without
 * CodingAgent wiring (e.g. in tests that boot a minimal kernel).
 */
final readonly class NullProviderCompatibilityResolver implements ProviderCompatibilityResolverInterface
{
    public function resolve(string $model): ProviderCompatibility
    {
        return new ProviderCompatibility();
    }
}
