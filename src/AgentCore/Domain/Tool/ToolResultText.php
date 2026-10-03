<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Domain\Tool;

/** Text in a tool result must be UTF-8, independently of its storage format. */
final class ToolResultText
{
    public const string FAILURE_MESSAGE = 'Tool call failed: its result contained malformed UTF-8.';

    public const string INSPECTION_FAILURE_MESSAGE = 'Tool call failed: its result could not be safely inspected.';

    public static function isValid(mixed $value): bool
    {
        return null === self::failureMessage($value);
    }

    public static function failureMessage(mixed $value, ?\SplObjectStorage $visited = null, int $depth = 0): ?string
    {
        if (\is_string($value)) {
            return mb_check_encoding($value, 'UTF-8') ? null : self::FAILURE_MESSAGE;
        }
        if ($depth > 512) {
            return self::INSPECTION_FAILURE_MESSAGE;
        }
        if (\is_object($value)) {
            $visited ??= new \SplObjectStorage();
            if ($visited->contains($value)) {
                return null;
            }
            $visited->attach($value);
            $value = get_mangled_object_vars($value);
        }
        if (!\is_array($value)) {
            return null;
        }
        foreach ($value as $key => $item) {
            $failure = self::failureMessage($key, $visited, $depth + 1)
                ?? self::failureMessage($item, $visited, $depth + 1);
            if (null !== $failure) {
                return $failure;
            }
        }

        return null;
    }

    public static function finalize(ToolResult $result): ToolResult
    {
        $failure = self::failureMessage($result->content) ?? self::failureMessage($result->details);
        if (null === $failure) {
            return $result;
        }

        return new ToolResult(
            toolCallId: $result->toolCallId,
            toolName: $result->toolName,
            content: [['type' => 'text', 'text' => $failure]],
            details: ['retryable' => false],
            isError: true,
        );
    }
}
