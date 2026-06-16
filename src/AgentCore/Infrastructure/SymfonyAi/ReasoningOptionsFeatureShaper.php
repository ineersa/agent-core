<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Infrastructure\SymfonyAi;

use Ineersa\AgentCore\Contract\ProviderCompatibilityFeatureShaperInterface;
use Ineersa\AgentCore\Domain\Model\ProviderRequest;
use Ineersa\AgentCore\Domain\Model\ProviderRequestOptionKeys;

/**
 * Merges pre-computed reasoning options into the provider request.
 *
 * CodingAgent pre-computes provider-specific reasoning options
 * (e.g. {@code enable_thinking}, {@code reasoning_effort},
 * {@code thinking.type}) from the Hatfield model catalog and the
 * active reasoning level. This shaper only merges them and strips
 * the internal key — it has zero knowledge of the model catalog.
 *
 * Activated when {@code 'reasoning'} is in the compat features array.
 */
final readonly class ReasoningOptionsFeatureShaper implements ProviderCompatibilityFeatureShaperInterface
{
    public const string FEATURE = 'reasoning';

    public function supports(array $compatFeatures): bool
    {
        return \in_array(self::FEATURE, $compatFeatures, true);
    }

    public function shape(
        string $model,
        array $input,
        array $options,
        array $compatFeatures,
    ): ?ProviderRequest {
        $reasoningOptions = \is_array($options[ProviderRequestOptionKeys::REASONING_OPTIONS] ?? null)
            ? $options[ProviderRequestOptionKeys::REASONING_OPTIONS] : null;

        if (null === $reasoningOptions || [] === $reasoningOptions) {
            return null;
        }

        $newOptions = $options;
        unset($newOptions[ProviderRequestOptionKeys::REASONING_OPTIONS]);

        return new ProviderRequest(options: array_merge($newOptions, $reasoningOptions));
    }
}
