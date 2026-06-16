<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Domain\Model;

/**
 * Shared constants for provider request option keys used between
 * AgentCore infrastructure and CodingAgent compat/request shapers.
 *
 * These keys are stripped from provider invocation options before they
 * reach the Symfony AI Platform provider.
 *
 * @see \Ineersa\AgentCore\Infrastructure\SymfonyAi\ModelResolverRoutingSubscriber
 * @see \Ineersa\AgentCore\Infrastructure\SymfonyAi\ProviderCompatibilityRequestShaper
 */
final class ProviderRequestOptionKeys
{
    /**
     * Internal option key carrying the user-facing reasoning level
     * (off|minimal|low|medium|high|xhigh). Stripped before options reach
     * the provider.
     */
    public const string REASONING = '_hatfield_reasoning';

    /**
     * Internal option key carrying the provider compatibility features array
     * (e.g. ['zai_tool_stream', 'requires_reasoning_content_on_assistant', 'reasoning']).
     * Consumed by compat feature shapers and stripped before the provider sees options.
     */
    public const string COMPAT_FEATURES = '_hatfield_compat_features';

    /**
     * Internal option key carrying pre-computed reasoning options
     * (e.g. ['enable_thinking' => true]) that the ReasoningOptionsFeatureShaper
     * merges into the provider options. Stripped before the provider sees options.
     */
    public const string REASONING_OPTIONS = '_hatfield_reasoning_options';

    private function __construct()
    {
    }
}
