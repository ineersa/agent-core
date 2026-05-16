<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Config\Ai;

/**
 * Provider/model compat quirks that affect request shaping or stream parsing.
 *
 * Internal Hatfield metadata — not something Symfony's generic bridge
 * understands by itself. The option/message mapping layer consumes these
 * flags to produce the correct invocation options for each provider/model.
 */
final readonly class AiCompat
{
    /**
     * @param bool|null   $supportsDeveloperRole   Whether the provider supports the OpenAI
     *                                             developer role. false means only
     *                                             system/user/assistant/tool roles.
     * @param bool|null   $supportsReasoningEffort Whether the provider accepts
     *                                             reasoning_effort. false means
     *                                             reasoning must use another mechanism.
     * @param string|null $thinkingFormat          The non-OpenAI thinking format name
     *                                             (e.g. zai for enable_thinking boolean).
     *                                             null means standard OpenAI reasoning_effort.
     * @param bool|null   $zaiToolStream           Whether this model supports z.ai streaming
     *                                             tool calls.
     */
    public function __construct(
        public ?bool $supportsDeveloperRole = null,
        public ?bool $supportsReasoningEffort = null,
        public ?string $thinkingFormat = null,
        public ?bool $zaiToolStream = null,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            supportsDeveloperRole: self::boolOrNull($data['supports_developer_role'] ?? null),
            supportsReasoningEffort: self::boolOrNull($data['supports_reasoning_effort'] ?? null),
            thinkingFormat: isset($data['thinking_format']) && \is_string($data['thinking_format']) ? $data['thinking_format'] : null,
            zaiToolStream: self::boolOrNull($data['zai_tool_stream'] ?? null),
        );
    }

    private static function boolOrNull(mixed $value): ?bool
    {
        if (\is_bool($value)) {
            return $value;
        }

        return null;
    }
}
