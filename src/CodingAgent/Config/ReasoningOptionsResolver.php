<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Config;

use Ineersa\CodingAgent\Config\Ai\AiModelDefinition;
use Ineersa\CodingAgent\Config\Ai\AiModelReference;
use Ineersa\CodingAgent\Config\Ai\HatfieldModelCatalog;

/**
 * Converts a global user-facing reasoning level + Hatfield model metadata
 * into provider invocation options such as reasoning_effort or enable_thinking.
 *
 * Reasoning level: off | minimal | low | medium | high | xhigh.
 * Returns an empty array when reasoning is not applicable.
 */
final readonly class ReasoningOptionsResolver
{
    /** Reasoning levels that are not "off". */
    private const array ACTIVE_LEVELS = ['minimal', 'low', 'medium', 'high', 'xhigh'];

    public function __construct(
        private HatfieldModelCatalog $catalog,
    ) {
    }

    /**
     * Produce provider invocation options for the given model and reasoning level.
     *
     * @return array<string, mixed>
     */
    public function resolve(AiModelReference $ref, string $level): array
    {
        $level = strtolower($level);

        if ('off' === $level) {
            return [];
        }

        if (!\in_array($level, self::ACTIVE_LEVELS, true)) {
            return [];
        }

        $model = $this->catalog->getModel($ref);
        if (null === $model || !$model->reasoning) {
            return [];
        }

        $map = $model->thinkingLevelMap;
        if ([] === $map) {
            return [];
        }

        $mappedValue = $map[$level] ?? null;
        if (null === $mappedValue) {
            return [];
        }

        $thinkingFormat = $this->thinkingFormat($ref, $model);

        // z.ai: enable_thinking boolean
        if ('zai' === $thinkingFormat) {
            return ['enable_thinking' => true];
        }

        // OpenAI-style: reasoning_effort
        if ($this->supportsReasoningEffort($ref)) {
            return ['reasoning_effort' => $mappedValue];
        }

        // No supported medium to express reasoning for this provider
        return [];
    }

    /**
     * Determine the effective thinking format for this model.
     *
     * Model-level compatibility overrides provider-level. Falls back to null
     * for standard OpenAI reasoning_effort.
     */
    private function thinkingFormat(AiModelReference $ref, AiModelDefinition $model): ?string
    {
        if (null !== $model->compatibility?->thinkingFormat) {
            return $model->compatibility->thinkingFormat;
        }

        $provider = $this->catalog->getProvider($ref->providerId);

        return $provider?->compatibility?->thinkingFormat;
    }

    /**
     * Whether this provider supports OpenAI-style reasoning_effort.
     *
     * Provider-level compatibility is authoritative. Model-level only matters
     * when the model has its own compatibility block. Defaults to true (the
     * AiCompatibility default) when no compatibility metadata exists.
     */
    private function supportsReasoningEffort(AiModelReference $ref): bool
    {
        $provider = $this->catalog->getProvider($ref->providerId);

        return $provider?->compatibility->supportsReasoningEffort ?? true;
    }
}
