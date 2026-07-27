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
 * Build hatfield.phar via the CastorTasks PHAR build pipeline.
 */
#[AsTask(name: 'phar:build', description: 'Build hatfield.phar')]
function phar_build(): void
{
    \CastorTasks\phar_build();
    echo "PHAR built successfully.\n";
}

/**
 * Ensure hatfield.phar exists (build if missing or stale).
 *
 * Failures propagate — do not swallow exceptions.
 */
#[AsTask(name: 'phar:ensure', description: 'Ensure hatfield.phar exists (build if missing or stale)')]
function phar_ensure(): void
{
    \CastorTasks\phar_ensure();
}

/**
 * Remove the worktree-local hatfield.phar, staging, and lock files.
 */
#[AsTask(name: 'phar:clean', description: 'Remove worktree-local hatfield.phar, staging, and locks')]
function phar_clean(): void
{
    $path = \CastorTasks\hatfield_phar_path();
    if (is_file($path) || is_link($path) || is_file(\CastorTasks\phar_freshness_marker_path($path))) {
        \CastorTasks\phar_remove_artifact_and_marker($path);
        echo "Removed {$path} (+ freshness marker)\n";
    } else {
        echo "No PHAR at {$path}\n";
    }

    $staging = \CastorTasks\hatfield_phar_staging_dir();
    if (is_dir($staging)) {
        \CastorTasks\remove_path_checked($staging);
        echo "Removed staging {$staging}\n";
    }

    $rootReal = realpath(__DIR__.'/..');
    $root = false !== $rootReal ? $rootReal : __DIR__.'/..';
    $lock = $root.'/'.\CastorTasks\PHAR_BUILD_LOCK;
    if (is_file($lock)) {
        \CastorTasks\remove_path_checked($lock);
        echo "Removed lock {$lock}\n";
    }
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
        $marker = \CastorTasks\phar_freshness_marker_path($path);
        echo 'Freshness marker: '.(is_file($marker) ? 'present' : 'missing').\PHP_EOL;
        $rootReal = realpath(__DIR__.'/..');
        $root = false !== $rootReal ? $rootReal : __DIR__.'/..';
        echo 'Stale: '.(\CastorTasks\phar_is_stale($root, $path) ? 'yes' : 'no').\PHP_EOL;
    }
}
