<?php

declare(strict_types=1);

namespace Ineersa\Hatfield\ExtensionApi;

/**
 * Decision DTO returned by ToolCallHookInterface::onToolCall().
 *
 * Use the named constructors to create instances:
 *   - ToolCallDecisionDTO::allow()               – proceed with execution
 *   - ToolCallDecisionDTO::block(string, array)  – deny with reason
 *   - ToolCallDecisionDTO::replaceResult(mixed)  – skip handler, use given result
 *
 * @see ToolCallHookInterface
 * @see ToolCallDecisionKindEnum
 */
final readonly class ToolCallDecisionDTO
{
    /**
     * @param array<string, mixed> $details optional structured metadata about the decision
     */
    private function __construct(
        public ToolCallDecisionKindEnum $kind,
        public ?string $reason = null,
        public mixed $result = null,
        public array $details = [],
    ) {
    }

    /**
     * Allow the tool call to proceed.
     */
    public static function allow(): self
    {
        return new self(kind: ToolCallDecisionKindEnum::Allow);
    }

    /**
     * Block the tool call with a reason.
     *
     * @param array<string, mixed> $details
     */
    public static function block(string $reason, array $details = []): self
    {
        return new self(kind: ToolCallDecisionKindEnum::Block, reason: $reason, details: $details);
    }

    /**
     * Replace the tool call result without invoking the handler.
     *
     * @param array<string, mixed> $details
     */
    public static function replaceResult(mixed $result, array $details = []): self
    {
        return new self(kind: ToolCallDecisionKindEnum::ReplaceResult, result: $result, details: $details);
    }
}
