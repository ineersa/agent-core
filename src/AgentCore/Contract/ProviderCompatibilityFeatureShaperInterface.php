<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Contract;

use Ineersa\AgentCore\Domain\Model\ProviderCompatibility;
use Ineersa\AgentCore\Domain\Model\ProviderRequest;

/**
 * A single provider-compatibility feature shaper, called during the
 * final normalization step after all {@see Hook\BeforeProviderRequestHookInterface}
 * hooks have completed.
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
     * Whether this feature shaper should apply for the resolved compatibility.
     */
    public function supports(ProviderCompatibility $compat): bool;

    /**
     * Shape the provider request for this feature.
     *
     * Return a {@see ProviderRequest} with any changed model/input/options,
     * or null to leave everything unchanged.
     *
     * @param array<string, mixed> $input   the current input array (post-hooks)
     * @param array<string, mixed> $options the current options array (post-hooks, may contain internal keys)
     */
    public function shape(
        string $model,
        array $input,
        array $options,
        ProviderCompatibility $compat,
    ): ?ProviderRequest;
}
