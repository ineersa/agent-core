<?php

declare(strict_types=1);

namespace Ineersa\Hatfield\ExtensionApi\Tool;

/**
 * Context DTO provided to ToolResultHookInterface::onToolResult().
 *
 * Contains identifier, invocation details, arguments, execution result,
 * and runtime metadata for a completed tool call.
 *
 * @see ToolResultHookInterface
 * @see ToolResultDecisionDTO
 */
final readonly class ToolResultContextDTO
{
    /**
     * @param array<string, mixed>             $arguments tool parameters that were passed
     * @param array<int, array<string, mixed>> $content   tool result content blocks
     * @param array<string, mixed>             $details   tool result details metadata
     * @param array<string, mixed>             $metadata  runtime context (e.g. session flags, provider metadata)
     */
    public function __construct(
        public string $toolCallId,
        public string $toolName,
        public array $arguments,
        public bool $isError,
        public array $content,
        public array $details,
        public ?string $runId = null,
        public ?int $turnNo = null,
        public ?string $cwd = null,
        public array $metadata = [],
    ) {
    }
}
