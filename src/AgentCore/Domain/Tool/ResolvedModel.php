<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Domain\Tool;

/**
 * Resolved model identifier, optional provider ID, reasoning level, and invocation options.
 *
 * Produced by {@see ModelResolverInterface::resolve()} and consumed by
 * {@see ModelResolverRoutingSubscriber} to set the model name, attach reasoning
 * metadata for {@see CompatRequestShaper}, and optionally select a specific provider
 * via {@see ModelRoutingEvent::setProvider()}.
 */
final readonly class ResolvedModel
{
    /**
     * @param array<string, mixed> $options
     */
    public function __construct(
        public string $model,
        public string $providerId = '',
        public string $reasoning = '',
        public array $options = [],
    ) {
    }
}
