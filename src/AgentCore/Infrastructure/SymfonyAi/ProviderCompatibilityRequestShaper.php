<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Infrastructure\SymfonyAi;

use Ineersa\AgentCore\Contract\ProviderCompatibilityFeatureShaperInterface;
use Ineersa\AgentCore\Domain\Model\ResolvedModel;

/**
 * Applies provider compatibility transformations before normal request hooks.
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
     * @param array<string, mixed> $input
     * @param array<string, mixed> $options provider-facing options only
     *
     * @return array{model: string, input: array<string, mixed>, options: array<string, mixed>}
     */
    public function shape(ResolvedModel $resolvedModel, array $input, array $options): array
    {
        $model = $resolvedModel->model;

        foreach ($this->featureShapers as $shaper) {
            if (!$shaper->supports($resolvedModel->compatFeatures)) {
                continue;
            }

            $request = $shaper->shape($model, $input, $options, $resolvedModel);
            if (null === $request) {
                continue;
            }

            $resolved = $request->applyOn($model, $input, $options);
            $model = $resolved['model'];
            $input = $resolved['input'];
            $options = $resolved['options'];
        }

        return [
            'model' => $model,
            'input' => $input,
            'options' => $options,
        ];
    }
}
