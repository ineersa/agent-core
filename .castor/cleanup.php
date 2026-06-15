<?php

declare(strict_types=1);

/**
 * Artifact cleanup task.
 *
 * Removes all temp/test artifacts: TUI E2E snapshots, failure
 * diagnostics, isolated test directories, and PHAR build staging.
 */

use Castor\Attribute\AsTask;

require_once __DIR__.'/../vendor/autoload.php';
require_once __DIR__.'/helpers.php';
require_once __DIR__.'/shared.php';

/**
 * Recursively remove a directory tree.  Used by the cleanup task
 * to remove generated temp/test artifacts (not a Castor task itself).
 */
function rmtree(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );

    foreach ($iterator as $entry) {
        $entry->isDir() ? rmdir((string) $entry) : unlink((string) $entry);
    }

    rmdir($dir);
}

/**
 * Remove all temp/test artifacts.
 */
#[AsTask(name: 'cleanup', namespace: 'clean', description: 'Remove all temp/test artifacts')]
function cleanup(): void
{
    $root = (false !== ($_rp = realpath(__DIR__.'/..')) ? $_rp : __DIR__.'/..');

    $dirsToRemove = [
        $root.'/var/tmp/tui-e2e-',
        $root.'/var/tmp/tui-failures',
        $root.'/var/tmp/test-',
        $root.'/var/tmp/phar-build',
    ];

    $toRemove = [];
    foreach ($dirsToRemove as $prefix) {
        // Strip trailing separator if present; glob for prefix*
        $base = rtrim($prefix, \DIRECTORY_SEPARATOR);
        $parent = dirname($base);
        if (!is_dir($parent)) {
            continue;
        }
        $pattern = $base.'*';
        $entries = glob($pattern);
        if (false !== $entries) {
            foreach ($entries as $entry) {
                if (is_dir($entry)) {
                    $toRemove[] = $entry;
                }
            }
        }
    }

    $noop = true;
    foreach ($toRemove as $dir) {
        rmtree($dir);
        echo 'Removed '.project_relative_path($dir).\PHP_EOL;
        $noop = false;
    }

    if ($noop) {
        echo 'Nothing to clean up.
';
    }
}
