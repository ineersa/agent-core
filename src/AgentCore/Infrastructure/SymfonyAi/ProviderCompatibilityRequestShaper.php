<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Infrastructure\SymfonyAi;

use Ineersa\AgentCore\Contract\ProviderCompatibilityFeatureShaperInterface;
use Ineersa\AgentCore\Contract\ProviderCompatibilityResolverInterface;
use Ineersa\AgentCore\Domain\Model\ProviderRequestOptionKeys;

/**
 * Final provider-compatibility normalization step.
 *
 * Runs AFTER all normal {@see \Ineersa\AgentCore\Contract\Hook\BeforeProviderRequestHookInterface}
 * hooks and BEFORE the Symfony AI platform invokes the provider.
 *
 * 1. Resolves {@see \Ineersa\AgentCore\Domain\Model\ProviderCompatibility} for
 *    the effective model via {@see ProviderCompatibilityResolverInterface}.
 * 2. Iterates tagged {@see ProviderCompatibilityFeatureShaperInterface} shapers
 *    and applies their transformations.
 * 3. Strips all internal option keys ({@see ProviderRequestOptionKeys}) so
 *    they never reach the provider.
 *
 * This design means no hook-priority ordering is needed between compat shapers,
 * no private marker options leak between hooks, and no third-party hook can
 * accidentally corrupt or consume internal compat flags.
 */
final readonly class ProviderCompatibilityRequestShaper
{
    /**
     * @param iterable<ProviderCompatibilityFeatureShaperInterface> $featureShapers
     */
    public function __construct(
        private ProviderCompatibilityResolverInterface $compatResolver,
        private iterable $featureShapers = [],
    ) {
    }

    /**
     * Shape the final provider request by applying compatibility normalization.
     *
     * @param string               $model   the effective model name (post-hooks)
     * @param array<string, mixed> $input   the input array (post-hooks)
     * @param array<string, mixed> $options the options array (post-hooks, may contain internal keys)
     *
     * @return array{model: string, input: array<string, mixed>, options: array<string, mixed>}
     */
    public function shape(string $model, array $input, array $options): array
    {
        $compat = $this->compatResolver->resolve($model);

        $resolvedModel = $model;
        $resolvedInput = $input;
        $resolvedOptions = $options;

        foreach ($this->featureShapers as $shaper) {
            if (!$shaper->supports($compat)) {
                continue;
            }

            $request = $shaper->shape($resolvedModel, $resolvedInput, $resolvedOptions, $compat);
            if (null === $request) {
                continue;
            }

            $resolved = $request->applyOn($resolvedModel, $resolvedInput, $resolvedOptions);
            $resolvedModel = $resolved['model'];
            $resolvedInput = $resolved['input'];
            $resolvedOptions = $resolved['options'];
        }

        // Strip all internal option keys before the provider sees them.
        $resolvedOptions = $this->stripInternalKeys($resolvedOptions);

        return [
            'model' => $resolvedModel,
            'input' => $resolvedInput,
            'options' => $resolvedOptions,
        ];
    }

    /**
     * Strip known internal option keys so they never leak into provider requests.
     *
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    private function stripInternalKeys(array $options): array
    {
        unset(
            $options[ProviderRequestOptionKeys::REASONING],
            $options[ProviderRequestOptionKeys::SUPPRESS_DEVELOPER_ROLE],
            $options[ProviderRequestOptionKeys::REQUIRES_REASONING_CONTENT_ON_ASSISTANT],
        );

        return $options;
    }
}
