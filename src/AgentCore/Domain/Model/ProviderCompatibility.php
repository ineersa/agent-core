<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Domain\Model;

use Ineersa\AgentCore\Contract\ProviderCompatibilityOptionEnum;

/**
 * Resolved provider compatibility configuration for a specific effective model.
 *
 * Returned by {@see \Ineersa\AgentCore\Contract\ProviderCompatibilityResolverInterface}
 * and consumed by {@see \Ineersa\AgentCore\Contract\ProviderCompatibilityFeatureShaperInterface}
 * feature shapers during the final compat-normalization step.
 *
 * Carries both enum-style boolean flags (tool_stream, reasoning_content injection)
 * and model-derived configuration (thinking format, reasoning-effort support) that
 * feature shapers need to produce correct provider options and message shapes.
 */
final readonly class ProviderCompatibility
{
    /**
     * @param list<ProviderCompatibilityOptionEnum> $options                 semantic compatibility flags
     * @param string|null                           $thinkingFormat          the non-OpenAI thinking format
     *                                                                       (zai, codex, deepseek) or null
     *                                                                       for standard OpenAI reasoning_effort
     * @param bool                                  $supportsReasoningEffort whether the provider
     *                                                                       accepts the OpenAI reasoning_effort key
     */
    public function __construct(
        public array $options = [],
        public ?string $thinkingFormat = null,
        public bool $supportsReasoningEffort = true,
    ) {
    }

    public function has(ProviderCompatibilityOptionEnum $option): bool
    {
        return \in_array($option, $this->options, true);
    }
}
