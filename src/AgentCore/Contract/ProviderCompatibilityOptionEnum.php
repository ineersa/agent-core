<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Contract;

/**
 * Provider/model compatibility options resolved by a
 * {@see ProviderCompatibilityResolverInterface} and consumed by
 * {@see ProviderCompatibilityFeatureShaperInterface} feature shapers.
 *
 * These are semantic flags, not stringly private request option markers.
 * The final {@see \Ineersa\AgentCore\Infrastructure\SymfonyAi\ProviderCompatibilityRequestShaper}
 * strips all internal option keys before the provider request goes out.
 */
enum ProviderCompatibilityOptionEnum: string
{
    /**
     * z.ai streaming tool-call deltas.
     * The feature shaper emits {@code tool_stream: true} into provider options.
     */
    case ZAI_TOOL_STREAM = 'zai_tool_stream';

    /**
     * DeepSeek: assistant history messages without a reasoning/thinking block
     * must include an empty reasoning_content field.
     * The feature shaper injects an empty Thinking content block.
     */
    case REQUIRES_REASONING_CONTENT_ON_ASSISTANT = 'requires_reasoning_content_on_assistant';
}
