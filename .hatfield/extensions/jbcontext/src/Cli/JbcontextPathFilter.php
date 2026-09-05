<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\Jbcontext\Cli;

/**
 * Rejects absolute and traversing path filters before CLI execution.
 */
final class JbcontextPathFilter
{
    public static function validate(?string $pathFilter): ?string
    {
        if (null === $pathFilter) {
            return null;
        }

        $value = trim($pathFilter);
        if ('' === $value) {
            return null;
        }

        if (str_starts_with($value, '/') || 1 === preg_match('#^[A-Za-z]:[\\\\/]#', $value)) {
            throw new \InvalidArgumentException('path_filter must be project-relative, not absolute.');
        }

        $normalized = str_replace('\\', '/', $value);
        $parts = explode('/', $normalized);
        foreach ($parts as $part) {
            if ('..' === $part) {
                throw new \InvalidArgumentException('path_filter must not contain ".." path segments.');
            }
        }

        return $value;
    }
}
