<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Config;

use Ineersa\AgentCore\Contract\ProviderCompatibilityOptionEnum;
use Ineersa\AgentCore\Contract\ProviderCompatibilityResolverInterface;
use Ineersa\AgentCore\Domain\Model\ProviderCompatibility;
use Ineersa\CodingAgent\Config\Ai\AiModelReference;
use Ineersa\CodingAgent\Config\Ai\HatfieldModelCatalog;

/**
 * Resolves provider compatibility from the Hatfield model catalog.
 *
 * Looks up the model definition for the given raw model name and translates
 * its AiCompatibility metadata into an AgentCore {@see ProviderCompatibility}
 * value object. Model-level compatibility overrides provider-level where
 * both exist.
 *
 * This is NOT a {@see \Ineersa\AgentCore\Contract\Hook\BeforeProviderRequestHookInterface}
 * hook. It is called during the final compat-normalization step, AFTER all
 * normal hooks, so no third-party code can see or corrupt the resolved flags.
 */
final readonly class HatfieldProviderCompatibilityResolver implements ProviderCompatibilityResolverInterface
{
    public function __construct(
        private HatfieldModelCatalog $catalog,
    ) {
    }

    public function resolve(string $model): ProviderCompatibility
    {
        $ref = $this->findModelRef($model);

        if (null === $ref) {
            return new ProviderCompatibility();
        }

        $modelDef = $this->catalog->getModel($ref);

        if (null === $modelDef) {
            return new ProviderCompatibility();
        }

        // Model-level compat takes precedence; fall back to provider-level.
        $compat = $modelDef->compatibility
            ?? $this->catalog->getProvider($ref->providerId)?->compatibility;

        $options = [];
        $thinkingFormat = null;
        $supportsReasoningEffort = true;

        if (null !== $compat) {
            if ($compat->zaiToolStream) {
                $options[] = ProviderCompatibilityOptionEnum::ZAI_TOOL_STREAM;
            }

            if ($compat->requiresReasoningContentOnAssistantMessages) {
                $options[] = ProviderCompatibilityOptionEnum::REQUIRES_REASONING_CONTENT_ON_ASSISTANT;
            }

            $thinkingFormat = $compat->thinkingFormat;
            $supportsReasoningEffort = $compat->supportsReasoningEffort;
        }

        return new ProviderCompatibility(
            options: $options,
            thinkingFormat: $thinkingFormat,
            supportsReasoningEffort: $supportsReasoningEffort,
        );
    }

    /**
     * Walk all configured models to find the AiModelReference whose
     * modelName matches the raw name being sent to the platform.
     */
    private function findModelRef(string $modelName): ?AiModelReference
    {
        foreach ($this->catalog->allModels() as $ref) {
            if ($ref->modelName === $modelName) {
                return $ref;
            }
        }

        return null;
    }
}
