<?php

declare(strict_types=1);

namespace Ineersa\Hatfield\ExtensionApi\Tool;

/**
 * Decision DTO returned by ToolResultHookInterface::onToolResult().
 *
 * Use the named constructors to create instances:
 *   - ToolResultDecisionDTO::keep()                                    – retain current result
 *   - ToolResultDecisionDTO::replace(?bool, ?array, ?array)            – replace result fields
 *
 * @see ToolResultHookInterface
 * @see ToolResultDecisionKindEnum
 */
final readonly class ToolResultDecisionDTO
{
    /**
     * @param array<int, array<string, mixed>>|null $content replacement result content blocks
     * @param array<string, mixed>|null             $details replacement result details
     */
    private function __construct(
        public ToolResultDecisionKindEnum $kind,
        public ?bool $isError = null,
        public ?array $content = null,
        public ?array $details = null,
    ) {
    }

    /**
     * Keep the current tool result unchanged.
     */
    public static function keep(): self
    {
        return new self(kind: ToolResultDecisionKindEnum::Keep);
    }

    /**
     * Replace selected fields of the tool result.
     *
     * @param array<int, array<string, mixed>>|null $content
     * @param array<string, mixed>|null             $details
     */
    public static function replace(
        ?bool $isError = null,
        ?array $content = null,
        ?array $details = null,
    ): self {
        return new self(kind: ToolResultDecisionKindEnum::Replace, isError: $isError, content: $content, details: $details);
    }
}
