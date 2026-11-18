<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\ObservationalMemory\Claude;

use Symfony\Component\Filesystem\Path;

/**
 * Claude Code names the Librarian project after the directory it was started
 * in. A start below a Git checkout's top level would register a stray
 * subdirectory project, so OM serves only the checkout root itself (or any
 * directory outside Git).
 */
final class ClaudeProjectRootPolicy
{
    /** @param string $root Resolved CLAUDE_PROJECT_DIR */
    public static function allows(string $root): bool
    {
        $directory = $root;
        while (!file_exists($directory.'/.git') && !is_link($directory.'/.git')) {
            $parent = Path::getDirectory($directory);
            if ($parent === $directory) {
                return true; // Non-Git projects remain supported.
            }
            $directory = $parent;
        }

        return $directory === $root;
    }
}
