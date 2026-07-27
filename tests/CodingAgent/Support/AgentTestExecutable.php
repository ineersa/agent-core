<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Support;

/**
 * Resolves the agent executable command for test subprocess spawning.
 *
 * When HATFIELD_BINARY_PATH is set (e.g. by Castor tasks that build the
 * PHAR first), returns [PHP_BINARY, <phar-path>] for ordinary PHAR/scripts.
 * When the resolved artifact is a fused native binary (same path as the
 * current PHP_BINARY), returns [artifact] so tests mirror production
 * relaunch topology. Falls back to the source-checkout bin/console path
 * for direct PHPUnit runs outside Castor.
 *
 * Usage:
 *   $cmd = AgentTestExecutable::command();
 *   // PHAR/source: [PHP_BINARY, path]
 *   // fused native: [path]
 *
 *   $path = AgentTestExecutable::path();
 *   // Returns the absolute path to bin/console, the PHAR, or the native binary.
 *
 * sourceConsoleCommand() is intentionally unchanged (always PHP + bin/console)
 * so replay/live controller tests keep loading APP_ENV=test DI.
 */
final class AgentTestExecutable
{
    /**
     * @return list<string> Command array: [PHP_BINARY, <executable>] or [native-binary]
     */
    public static function command(): array
    {
        $binaryPath = self::resolveBinaryPath();

        if (self::isFusedNativeExecutable($binaryPath)) {
            return [$binaryPath];
        }

        return [\PHP_BINARY, $binaryPath];
    }

    /**
     * Source-checkout console only (never the PHAR).
     *
     * Live controller E2E with APP_ENV=test loads dev-only bundles and
     * config/services_test.yaml; the PHAR excludes those dependencies.
     *
     * @return string[] [PHP_BINARY, <project>/bin/console]
     */
    public static function sourceConsoleCommand(): array
    {
        $projectDir = ProjectDir::get();
        $script = $projectDir.'/bin/console';

        return [\PHP_BINARY, $script];
    }

    /**
     * Absolute path to the agent executable.
     */
    public static function path(): string
    {
        return self::resolveBinaryPath();
    }

    /**
     * Resolve the binary path from HATFIELD_BINARY_PATH env var, or fall back
     * to the source-checkout bin/console.
     */
    private static function resolveBinaryPath(): string
    {
        $binaryPath = getenv('HATFIELD_BINARY_PATH');

        if (false !== $binaryPath && '' !== $binaryPath) {
            // Resolve relative paths against the runtime cwd.
            if (!str_starts_with($binaryPath, '/')) {
                $cwd = getcwd();
                if (false !== $cwd) {
                    $binaryPath = $cwd.'/'.$binaryPath;
                }
            }

            if (is_file($binaryPath) && is_readable($binaryPath)) {
                return $binaryPath;
            }
        }

        // Fallback: source-checkout bin/console.
        $projectDir = ProjectDir::get();

        return $projectDir.'/bin/console';
    }

    private static function isFusedNativeExecutable(string $path): bool
    {
        if (!is_file($path)) {
            return false;
        }

        $phpBinary = \PHP_BINARY;
        $resolvedPath = realpath($path);
        $resolvedPhp = realpath($phpBinary);
        if (false === $resolvedPath || false === $resolvedPhp) {
            return $path === $phpBinary;
        }

        return $resolvedPath === $resolvedPhp;
    }
}
