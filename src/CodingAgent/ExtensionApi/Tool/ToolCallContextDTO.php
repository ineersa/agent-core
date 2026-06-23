<?php

declare(strict_types=1);

namespace Ineersa\Hatfield\ExtensionApi\Tool;

/**
 * Context DTO provided to ToolCallHookInterface::onToolCall().
 *
 * Contains identifier, invocation details, arguments, and runtime metadata
 * for a pending tool call. This is a public API DTO; all properties are
 * readonly and the class is final+readonly.
 *
 * @see ToolCallHookInterface
 * @see ToolCallDecisionDTO
 */
final readonly class ToolCallContextDTO
{
    /**
     * @param array<string, mixed> $arguments tool parameters interpreted from LLM output
     * @param array<string, mixed> $metadata  runtime context (e.g. session flags, provider metadata)
     */
    public function __construct(
        public string $toolCallId,
        public string $toolName,
        public array $arguments,
        public int $orderIndex,
        public ?string $runId = null,
        public ?int $turnNo = null,
        public ?string $cwd = null,
        public array $metadata = [],
    ) {
    }
}
