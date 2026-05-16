<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Config\Ai;

/**
 * A model definition from the Hatfield model catalog.
 *
 * Owns all model metadata the agent needs: context window, max tokens,
 * input modalities, tool-calling, reasoning, thinking-level map,
 * cost, and provider-specific compat quirks.
 */
final readonly class AiModelDefinition
{
    /**
     * @param string                     $id               Model name used in API requests (e.g. glm-5.1, deepseek-v4-pro)
     * @param string|null                $name             Human-readable display name (e.g. "GLM 5.1")
     * @param int|null                   $contextWindow    Max context window in tokens
     * @param int|null                   $maxTokens        Max output tokens
     * @param list<string>               $input            Accepted input modalities (text, image, etc.)
     * @param bool                       $toolCalling      Whether this model supports tool/function calling
     * @param bool                       $reasoning        Whether this model supports reasoning/thinking
     * @param array<string, string|null> $thinkingLevelMap Map from user-facing reasoning level
     *                                                     to provider-specific value (e.g. minimal→high, xhigh→max)
     * @param AiCompat|null              $compat           Per-model compat overrides
     * @param AiCost|null                $cost             Token pricing
     */
    public function __construct(
        public string $id,
        public ?string $name = null,
        public ?int $contextWindow = null,
        public ?int $maxTokens = null,
        public array $input = [],
        public bool $toolCalling = false,
        public bool $reasoning = false,
        public array $thinkingLevelMap = [],
        public ?AiCompat $compat = null,
        public ?AiCost $cost = null,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data, string $modelId): self
    {
        return new self(
            id: $modelId,
            name: $data['name'] ?? null,
            contextWindow: isset($data['context_window']) && \is_int($data['context_window']) ? $data['context_window'] : null,
            maxTokens: isset($data['max_tokens']) && \is_int($data['max_tokens']) ? $data['max_tokens'] : null,
            input: \is_array($data['input'] ?? null) ? array_values(array_map('\strval', $data['input'])) : [],
            toolCalling: (bool) ($data['tool_calling'] ?? false),
            reasoning: (bool) ($data['reasoning'] ?? false),
            thinkingLevelMap: \is_array($data['thinking_level_map'] ?? null) ? array_map(
                static fn (mixed $v): ?string => \is_string($v) ? $v : (\is_scalar($v) ? (string) $v : null),
                $data['thinking_level_map'],
            ) : [],
            compat: isset($data['compat']) && \is_array($data['compat']) ? AiCompat::fromArray($data['compat']) : null,
            cost: isset($data['cost']) && \is_array($data['cost']) ? AiCost::fromArray($data['cost']) : null,
        );
    }
}
