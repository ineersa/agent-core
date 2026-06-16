<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Infrastructure\SymfonyAi;

use Ineersa\AgentCore\Contract\ProviderCompatibilityFeatureShaperInterface;
use Ineersa\AgentCore\Domain\Model\ProviderRequest;

/**
 * Emits {@code tool_stream: true} into provider options for
 * z.ai models that support streaming tool-call deltas.
 *
 * Activated when {@code 'zai_tool_stream'} is in the compat features array.
 */
final readonly class ZaiToolStreamFeatureShaper implements ProviderCompatibilityFeatureShaperInterface
{
    private const string FEATURE = 'zai_tool_stream';

    public function supports(array $compatFeatures): bool
    {
        return \in_array(self::FEATURE, $compatFeatures, true);
    }

    public function shape(
        string $model,
        array $input,
        array $options,
        array $compatFeatures,
    ): ProviderRequest {
        return new ProviderRequest(options: array_merge($options, ['tool_stream' => true]));
    }
}
