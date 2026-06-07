<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tool\ToolQuestion;

/**
 * Shared boolean-answer resolver for tool question answer_tool_question commands.
 *
 * Extracted from AnswerToolQuestionHandler and InProcessAgentSessionClient
 * to eliminate duplicated private resolveAnswer() logic.
 *
 * Accepted formats:
 *   - bool: returned as-is
 *   - string: 'yes', 'no', 'true', 'false', '1', '0' (case-insensitive, trimmed)
 *   - int: 1 => true, anything else => false
 *   - null/other: false
 */
final readonly class ToolQuestionAnswerResolver
{
    public function resolve(mixed $answer): bool
    {
        if (\is_bool($answer)) {
            return $answer;
        }

        if (\is_string($answer)) {
            $lower = strtolower(trim($answer));

            return \in_array($lower, ['yes', 'true', '1'], true);
        }

        if (\is_int($answer)) {
            return 1 === $answer;
        }

        return false;
    }
}
