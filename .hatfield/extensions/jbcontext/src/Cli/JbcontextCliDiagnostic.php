<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\Jbcontext\Cli;

/**
 * Formats jbcontext CLI stderr for model/status surfaces.
 *
 * Bounds size only. Does not classify, redact, or invent guidance.
 * Routine logs must keep the stable error code and never copy this text.
 */
final class JbcontextCliDiagnostic
{
    public const int MAX_CHARS = 500;

    public static function format(string $stderr): ?string
    {
        $trimmed = trim($stderr);
        if ('' === $trimmed) {
            return null;
        }

        $normalized = preg_replace('/\s+/u', ' ', $trimmed) ?? $trimmed;
        $normalized = trim($normalized);
        if ('' === $normalized) {
            return null;
        }

        if (mb_strlen($normalized) > self::MAX_CHARS) {
            $normalized = mb_substr($normalized, 0, self::MAX_CHARS - 1).'…';
        }

        return 'JB Context error: "'.$normalized.'"';
    }
}
