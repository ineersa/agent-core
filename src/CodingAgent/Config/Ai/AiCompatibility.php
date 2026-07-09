<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Config\Ai;

/**
 * Provider/model compatibility quirks that affect request shaping or stream parsing.
 *
 * Internal Hatfield metadata — not something Symfony's generic bridge
 * understands by itself. The option/message mapping layer consumes these
 * flags to produce the correct invocation options for each provider/model.
 */
final readonly class AiCompatibility
{
    /**
     * @param bool        $supportsDeveloperRole                       Whether the provider supports the OpenAI
     *                                                                 developer role. false means only
     *                                                                 system/user/assistant/tool roles.
     * @param bool        $supportsReasoningEffort                     Whether the provider accepts
     *                                                                 reasoning_effort. true means standard
     *                                                                 OpenAI reasoning_effort is supported;
     *                                                                 false means reasoning must use another
     *                                                                 mechanism.
     * @param bool        $supportsReasoningEffortExplicit             When true, {@see supportsReasoningEffort}
     *                                                                 was set explicitly in YAML (or via
     *                                                                 {@see fromArray}) and overrides the
     *                                                                 parent provider default. When false on a
     *                                                                 model block, provider-level
     *                                                                 supports_reasoning_effort applies.
     * @param string|null $thinkingFormat                              The non-OpenAI thinking format name
     *                                                                 (e.g. zai for thinking.type and
     *                                                                 clear_thinking). null means standard
     *                                                                 OpenAI reasoning_effort.
     * @param bool        $zaiToolStream                               Whether this model supports z.ai streaming
     *                                                                 tool calls.
     * @param bool        $requiresReasoningContentOnAssistantMessages whether assistant messages without
     *                                                                 thinking must include an empty
     *                                                                 reasoning_content field (DeepSeek)
     */
    public function __construct(
        public bool $supportsDeveloperRole = false,
        public bool $supportsReasoningEffort = true,
        public bool $supportsReasoningEffortExplicit = false,
        public ?string $thinkingFormat = null,
        public bool $zaiToolStream = false,
        public bool $requiresReasoningContentOnAssistantMessages = false,
    ) {
    }

    public function hasExplicitSupportsReasoningEffort(): bool
    {
        return $this->supportsReasoningEffortExplicit;
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            supportsDeveloperRole: self::boolOrDefault($data['supports_developer_role'] ?? null, false),
            supportsReasoningEffort: self::boolOrDefault($data['supports_reasoning_effort'] ?? null, true),
            supportsReasoningEffortExplicit: \array_key_exists('supports_reasoning_effort', $data),
            thinkingFormat: isset($data['thinking_format']) && \is_string($data['thinking_format']) ? $data['thinking_format'] : null,
            zaiToolStream: self::boolOrDefault($data['zai_tool_stream'] ?? null, false),
            requiresReasoningContentOnAssistantMessages: self::boolOrDefault($data['requires_reasoning_content_on_assistant_messages'] ?? null, false),
        );
    }

    /**
     * Return the boolean value if it is a boolean, otherwise return the default.
     */
    private static function boolOrDefault(mixed $value, bool $default): bool
    {
        if (\is_bool($value)) {
            return $value;
        }

        return $default;
    }
}
