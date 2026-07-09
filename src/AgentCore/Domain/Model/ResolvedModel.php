<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Domain\Model;

/**
 * Resolved model identifier, optional provider ID, reasoning level, compat features,
 * pre-computed reasoning options, and invocation options.
 *
 * Produced by {@see ModelResolverInterface::resolve()} and consumed by
 * {@see ModelResolverRoutingSubscriber} to set the model name, attach reasoning
 * and compat metadata for the final compat-normalization step, and optionally
 * select a specific provider via {@see ModelRoutingEvent::setProvider()}.
 */
final readonly class ResolvedModel
{
    /**
     * @param array<string, mixed> $options          invocation options merged into provider options
     * @param list<string>         $compatFeatures   provider compatibility features to activate:
     *                                               'zai_tool_stream', 'requires_reasoning_content_on_assistant',
     *                                               'reasoning'
     * @param array<string, mixed> $reasoningOptions pre-computed reasoning options (e.g.
     *                                               ['thinking' => ['type' => 'enabled']]) already provider-specific
     */
    public function __construct(
        public string $model,
        public string $providerId = '',
        public string $reasoning = '',
        public array $options = [],
        public array $compatFeatures = [],
        public array $reasoningOptions = [],
    ) {
    }
}
