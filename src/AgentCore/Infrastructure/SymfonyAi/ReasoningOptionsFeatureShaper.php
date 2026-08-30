<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Infrastructure\SymfonyAi;

use Ineersa\AgentCore\Contract\ProviderCompatibilityFeatureShaperInterface;
use Ineersa\AgentCore\Domain\Model\ProviderRequest;
use Ineersa\AgentCore\Domain\Model\ResolvedModel;

/**
 * Merges pre-computed reasoning options into the provider request.
 *
 * CodingAgent pre-computes provider-specific reasoning options
 * (e.g. {@code thinking.type}, {@code reasoning_effort},
 * {@code reasoning.effort}) from the Hatfield model catalog and the
 * active reasoning level. This shaper only merges the resolved options;
 * it has no knowledge of the model catalog.
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
        ResolvedModel $resolvedModel,
    ): ?ProviderRequest {
        if ([] === $resolvedModel->reasoningOptions) {
            return null;
        }

        return new ProviderRequest(options: array_merge($options, $resolvedModel->reasoningOptions));
    }
}
