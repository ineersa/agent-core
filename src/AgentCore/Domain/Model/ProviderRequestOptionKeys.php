<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Domain\Model;

/**
 * Shared constants for provider request option keys used between
 * AgentCore infrastructure and CodingAgent compat/request shapers.
 *
 * These keys are stripped from provider invocation options before they
 * reach the Symfony AI Platform provider. They carry cross-cutting
 * concerns such as reasoning level and role-suppression flags.
 *
 * @see \Ineersa\AgentCore\Infrastructure\SymfonyAi\ModelResolverRoutingSubscriber
 * @see \Ineersa\CodingAgent\Config\CompatRequestShaper
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
     * Internal option key signaling that the provider does not support the
     * OpenAI developer role. Message converters should suppress developer
     * messages when this flag is present.
     */
    public const string SUPPRESS_DEVELOPER_ROLE = '_hatfield_suppress_developer_role';

    private function __construct()
    {
    }
}
