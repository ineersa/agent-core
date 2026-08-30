<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Domain\Model;

/**
 * Final model identity and provider-facing options resolved for one invocation.
 *
 * Internal Hatfield control data never belongs in providerOptions. The resolver
 * maps only values intentionally supported by the selected provider, such as a
 * Codex prompt_cache_key.
 */
final readonly class ResolvedModel
{
    /**
     * @param array<string, mixed> $providerOptions  options intentionally passed to the provider
     * @param list<string>         $compatFeatures   provider compatibility features to activate:
     *                                               'zai_tool_stream', 'requires_reasoning_content_on_assistant',
     *                                               'reasoning'
     * @param array<string, mixed> $reasoningOptions pre-computed reasoning options (e.g.
     *                                               ['thinking' => ['type' => 'enabled', 'clear_thinking' => false]]) already provider-specific
     */
    public function __construct(
        public string $model,
        public string $providerId = '',
        public string $reasoning = '',
        public array $providerOptions = [],
        public array $compatFeatures = [],
        public array $reasoningOptions = [],
    ) {
    }
}
