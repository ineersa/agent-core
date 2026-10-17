<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Domain\Tool\Completion;

/** UTF-8 byte helpers for retained completion presentation bounds. */
final class RetainedToolCompletionUtf8
{
    public static function byteLength(string $value): int
    {
        return \strlen($value);
    }

    public static function truncate(string $value, int $maxBytes): string
    {
        if ($maxBytes < 0) {
            throw new \InvalidArgumentException('UTF-8 truncate maxBytes must be non-negative.');
        }
        if (\strlen($value) <= $maxBytes) {
            return $value;
        }

        $cut = mb_strcut($value, 0, $maxBytes, 'UTF-8');
        if (!mb_check_encoding($cut, 'UTF-8')) {
            throw new \InvalidArgumentException('UTF-8 truncate produced invalid text.');
        }

        return $cut;
    }

    public static function requireValid(string $value, string $field): void
    {
        if (!mb_check_encoding($value, 'UTF-8')) {
            throw new \InvalidArgumentException(\sprintf('Retained tool completion %s must be valid UTF-8.', $field));
        }
    }
}
