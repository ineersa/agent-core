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

    private function __construct()
    {
    }
}
