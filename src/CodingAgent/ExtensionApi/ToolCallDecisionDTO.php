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
 *   - ToolCallDecisionDTO::requireApproval(...)  – request human approval via interrupt
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

    /**
     * Request human approval via the HITL interrupt flow.
     *
     * The tool call is replaced by an interrupt payload that pauses the run at
     * WaitingHuman. The human response (from TUI or controller) is fed back to
     * the LLM as a user message, allowing the LLM to retry the blocked call.
     *
     * @param array<string, mixed> $schema  JSON Schema for the expected answer shape
     * @param array<string, mixed> $details extension-specific metadata (category, command, path, etc.)
     */
    public static function requireApproval(
        string $prompt,
        ?string $questionId = null,
        array $schema = ['type' => 'string'],
        array $details = [],
    ): self {
        $merged = $details;
        $merged['prompt'] = $prompt;
        $merged['schema'] = $schema;

        if (null !== $questionId) {
            $merged['question_id'] = $questionId;
        }

        return new self(kind: ToolCallDecisionKindEnum::RequireApproval, details: $merged);
    }
}
