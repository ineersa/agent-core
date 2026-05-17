<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Contract\Model;

use Symfony\AI\Platform\ProviderInterface;

/**
 * Registry that provides Symfony AI Platform providers by identifier.
 *
 * Consumed by {@see ModelResolverRoutingSubscriber} to select a specific
 * provider when the resolved model includes a provider ID, allowing the
 * routing subscriber to short-circuit catalog-based routing via
 * {@see ModelRoutingEvent::setProvider()}.
 */
interface ProviderRegistryInterface
{
    /**
     * Get a provider by its identifier (e.g. "deepseek", "zai", "llama_cpp").
     */
    public function get(string $id): ?ProviderInterface;
}
