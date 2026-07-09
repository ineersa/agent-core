<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Config;

use Ineersa\CodingAgent\Config\Ai\AiModelDefinition;
use Ineersa\CodingAgent\Config\Ai\AiModelReference;
use Ineersa\CodingAgent\Config\Ai\HatfieldModelCatalog;

/**
 * Converts a global user-facing reasoning level + Hatfield model metadata
 * into provider invocation options such as reasoning_effort or z.ai thinking.type.
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

        if (!\in_array($level, ['off', ...self::ACTIVE_LEVELS], true)) {
            return [];
        }

        $model = $this->catalog->getModel($ref);
        if (null === $model) {
            return [];
        }

        if ('off' === $level) {
            if (!$model->reasoning) {
                return [];
            }

            if ('zai' === $this->thinkingFormat($ref, $model)) {
                return ['thinking' => ['type' => 'disabled']];
            }

            return [];
        }

        if (!$model->reasoning) {
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

        // z.ai: thinking.type + clear_thinking; optional reasoning_effort when supported
        if ('zai' === $thinkingFormat) {
            $result = [
                'thinking' => [
                    'type' => 'enabled',
                    'clear_thinking' => false,
                ],
            ];
            if ($this->supportsReasoningEffort($ref, $model)) {
                $result['reasoning_effort'] = $mappedValue;
            }

            return $result;
        }

        // Codex Responses API: reasoning.effort format
        if ('codex' === $thinkingFormat) {
            return ['reasoning' => ['effort' => $mappedValue, 'summary' => 'auto']];
        }

        // DeepSeek: thinking.type + optional reasoning_effort
        if ('deepseek' === $thinkingFormat) {
            $result = ['thinking' => ['type' => 'enabled']];
            if ($this->supportsReasoningEffort($ref, $model)) {
                $result['reasoning_effort'] = $mappedValue;
            }

            return $result;
        }

        // OpenAI-style: reasoning_effort
        if ($this->supportsReasoningEffort($ref, $model)) {
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
     * Whether this model/provider supports OpenAI-style reasoning_effort.
     *
     * Field-level override: explicit model-level supports_reasoning_effort wins
     * over provider-level; otherwise provider explicit value, else default true.
     * A model block with only zai_tool_stream must not imply supports_reasoning_effort.
     */
    private function supportsReasoningEffort(AiModelReference $ref, ?AiModelDefinition $model = null): bool
    {
        $model ??= $this->catalog->getModel($ref);

        if (null !== $model?->compatibility && $model->compatibility->hasExplicitSupportsReasoningEffort()) {
            return $model->compatibility->supportsReasoningEffort;
        }

        $provider = $this->catalog->getProvider($ref->providerId);
        if (null !== $provider?->compatibility && $provider->compatibility->hasExplicitSupportsReasoningEffort()) {
            return $provider->compatibility->supportsReasoningEffort;
        }

        return $provider?->compatibility->supportsReasoningEffort ?? true;
    }
}
