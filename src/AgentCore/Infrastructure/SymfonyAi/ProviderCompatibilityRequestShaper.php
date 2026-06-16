<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Infrastructure\SymfonyAi;

use Ineersa\AgentCore\Contract\ProviderCompatibilityFeatureShaperInterface;
use Ineersa\AgentCore\Domain\Model\ProviderRequestOptionKeys;

/**
 * Final provider-compatibility normalization step.
 *
 * Runs BEFORE all normal {@see \Ineersa\AgentCore\Contract\Hook\BeforeProviderRequestHookInterface}
 * hooks and BEFORE the Symfony AI platform invokes the provider.
 *
 * 1. Reads the active compat features from the internal option key
 *    {@see ProviderRequestOptionKeys::COMPAT_FEATURES}.
 * 2. Iterates tagged {@see ProviderCompatibilityFeatureShaperInterface} shapers
 *    and applies their transformations.
 * 3. Strips all internal option keys ({@see ProviderRequestOptionKeys}) so
 *    they never reach the provider.
 *
 * CodingAgent resolves the compat features from its config/catalog and passes
 * them here as a simple array — no resolver interface, no DTO layer, no
 * model-name-based lookup in AgentCore.
 */
final readonly class ProviderCompatibilityRequestShaper
{
    /**
     * @param iterable<ProviderCompatibilityFeatureShaperInterface> $featureShapers
     */
    public function __construct(
        private iterable $featureShapers = [],
    ) {
    }

    /**
     * Shape the final provider request by applying compatibility normalization.
     *
     * @param string               $model   the effective model name
     * @param array<string, mixed> $input   the input array
     * @param array<string, mixed> $options the options array (may contain internal keys)
     *
     * @return array{model: string, input: array<string, mixed>, options: array<string, mixed>}
     */
    public function shape(string $model, array $input, array $options): array
    {
        $compatFeatures = $this->extractCompatFeatures($options);

        $resolvedModel = $model;
        $resolvedInput = $input;
        $resolvedOptions = $options;

        foreach ($this->featureShapers as $shaper) {
            if (!$shaper->supports($compatFeatures)) {
                continue;
            }

            $request = $shaper->shape($resolvedModel, $resolvedInput, $resolvedOptions, $compatFeatures);
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
     * Extract the compat features array from options and remove it from
     * the options so the shapers don't need to see the raw option key.
     *
     * @param array<string, mixed> $options
     *
     * @return list<string>
     */
    private function extractCompatFeatures(array &$options): array
    {
        $features = \is_array($options[ProviderRequestOptionKeys::COMPAT_FEATURES] ?? null)
            ? $options[ProviderRequestOptionKeys::COMPAT_FEATURES] : [];

        unset($options[ProviderRequestOptionKeys::COMPAT_FEATURES]);

        return $features;
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
            $options[ProviderRequestOptionKeys::REASONING_OPTIONS],
        );

        return $options;
    }
}
