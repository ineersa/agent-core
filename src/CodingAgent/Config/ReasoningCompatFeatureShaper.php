<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Config;

use Ineersa\AgentCore\Contract\ProviderCompatibilityFeatureShaperInterface;
use Ineersa\AgentCore\Domain\Model\ProviderCompatibility;
use Ineersa\AgentCore\Domain\Model\ProviderRequest;
use Ineersa\AgentCore\Domain\Model\ProviderRequestOptionKeys;
use Ineersa\CodingAgent\Config\Ai\HatfieldModelCatalog;

/**
 * Resolves reasoning options (enable_thinking, reasoning_effort, thinking)
 * from the user-facing reasoning level and model compatibility.
 *
 * Reads the internal {@see ProviderRequestOptionKeys::REASONING} key from
 * options, resolves provider-specific reasoning options via
 * {@see ReasoningOptionsResolver}, strips the internal key, and merges
 * the resolved provider options.
 *
 * Always active — runs for every invocation but no-ops when no reasoning
 * key is present or reasoning is off.
 */
final readonly class ReasoningCompatFeatureShaper implements ProviderCompatibilityFeatureShaperInterface
{
    public function __construct(
        private HatfieldModelCatalog $catalog,
    ) {
    }

    public function supports(ProviderCompatibility $compat): bool
    {
        // Always active because reasoning depends on the request-level
        // reasoning key, not just model compat. The shape() method no-ops
        // when the key is absent.
        return true;
    }

    public function shape(
        string $model,
        array $input,
        array $options,
        ProviderCompatibility $compat,
    ): ?ProviderRequest {
        $reasoningLevel = \is_string($options[ProviderRequestOptionKeys::REASONING] ?? null)
            ? $options[ProviderRequestOptionKeys::REASONING] : null;

        if (null === $reasoningLevel) {
            return null;
        }

        $ref = $this->findModelRef($model);

        if (null === $ref) {
            // No model found — strip reasoning key but nothing else.
            $cleanedOptions = $options;
            unset($cleanedOptions[ProviderRequestOptionKeys::REASONING]);

            return new ProviderRequest(options: $cleanedOptions);
        }

        $resolver = new ReasoningOptionsResolver($this->catalog);
        $reasoningOptions = $resolver->resolve($ref, $reasoningLevel);

        $newOptions = $options;
        unset($newOptions[ProviderRequestOptionKeys::REASONING]);

        if ([] !== $reasoningOptions) {
            $newOptions = array_merge($newOptions, $reasoningOptions);
        }

        if ($newOptions === $options) {
            return null;
        }

        return new ProviderRequest(options: $newOptions);
    }

    /**
     * Walk all configured models to find the AiModelReference matching
     * the given model name.
     */
    private function findModelRef(string $modelName): ?Ai\AiModelReference
    {
        foreach ($this->catalog->allModels() as $ref) {
            if ($ref->modelName === $modelName) {
                return $ref;
            }
        }

        return null;
    }
}
