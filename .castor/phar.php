<?php

declare(strict_types=1);

/**
 * PHAR packaging tasks.
 *
 * These are thin wrappers that delegate the actual PHAR build/ensure
 * logic to the CastorTasks namespace (helpers.php).
 *
 * PHAR artifacts are worktree-local (var/tmp/phar/) so sibling
 * worktrees do not clobber each other.
 */

use Castor\Attribute\AsTask;

require_once __DIR__.'/../vendor/autoload.php';
require_once __DIR__.'/helpers.php';

/**
 * Build hatfield.phar via the phar:build Symfony Console command.
 */
#[AsTask(name: 'phar:build', description: 'Build hatfield.phar')]
function phar_build(): void
{
    // Only build the project-local PHAR needed for controller/TUI subprocess
    // tests; the real CI artifact lives in tools/phar/build-phar.php and only
    // needs to be rebuilt when the source packaging script changes.
    passthru(\PHP_BINARY.' '.__DIR__.'/../bin/console phar:build', $exitCode);
    if (0 !== $exitCode) {
        throw new RuntimeException("phar:build console command exited with code {$exitCode}");
    }
}

/**
 * Ensure hatfield.phar exists (build if missing or stale).
 */
#[AsTask(name: 'phar:ensure', description: 'Ensure hatfield.phar exists (build if missing or stale)')]
function phar_ensure(): void
{
    try {
        \CastorTasks\phar_ensure();
    } catch (Throwable $e) {
        echo "phar:ensure error: {$e->getMessage()}
";
    }
}

/**
 * Remove the worktree-local hatfield.phar.
 */
#[AsTask(name: 'phar:clean', description: 'Remove worktree-local hatfield.phar')]
function phar_clean(): void
{
    $path = \CastorTasks\hatfield_phar_path();
    if (is_file($path) && !unlink($path)) {
        throw new RuntimeException("Failed to remove {$path}");
    }
    echo "Removed {$path}
";
}

/**
 * Display PHAR path and build timestamp.
 */
#[AsTask(name: 'phar:info', description: 'Display PHAR path and build timestamp')]
function phar_info(): void
{
    $path = \CastorTasks\hatfield_phar_path();
    echo 'PHAR path: '.$path.\PHP_EOL;
    echo 'Exists: '.(is_file($path) ? 'yes' : 'no').\PHP_EOL;
    if (is_file($path)) {
        echo 'Size: '.filesize($path).' bytes'.\PHP_EOL;
        echo 'Modified: '.date(\DATE_ATOM, filemtime($path)).\PHP_EOL;
    }
}
