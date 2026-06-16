<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Contract;

use Ineersa\AgentCore\Domain\Model\ProviderRequest;

/**
 * A single provider-compatibility feature shaper, called during the
 * compat normalization step BEFORE normal
 * {@see Hook\BeforeProviderRequestHookInterface} hooks.
 *
 * Feature shapers are tagged with {@code agent_core.provider_compatibility_feature_shaper}
 * and iterated by {@see \Ineersa\AgentCore\Infrastructure\SymfonyAi\ProviderCompatibilityRequestShaper}.
 *
 * Each shaper checks whether its feature is active via {@see supports()} and,
 * if so, applies the necessary options or input/message transformations via
 * {@see shape()}.
 */
interface ProviderCompatibilityFeatureShaperInterface
{
    /**
     * Whether this feature shaper should apply given the active compat features.
     *
     * @param list<string> $compatFeatures the active compat features from the model resolver
     */
    public function supports(array $compatFeatures): bool;

    /**
     * Shape the provider request for this feature.
     *
     * Return a {@see ProviderRequest} with any changed model/input/options,
     * or null to leave everything unchanged.
     *
     * @param array<string, mixed> $input          the current input array
     * @param array<string, mixed> $options        the current options array (may contain internal keys)
     * @param list<string>         $compatFeatures the active compat features from the model resolver
     */
    public function shape(
        string $model,
        array $input,
        array $options,
        array $compatFeatures,
    ): ?ProviderRequest;
}
