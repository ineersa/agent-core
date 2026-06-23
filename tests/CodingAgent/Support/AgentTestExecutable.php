<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Support;

/**
 * Resolves the agent executable command for test subprocess spawning.
 *
 * When HATFIELD_BINARY_PATH is set (e.g. by Castor tasks that build the
 * PHAR first), returns [PHP_BINARY, <phar-path>]. Falls back to the
 * source-checkout bin/console path for direct PHPUnit runs outside Castor.
 *
 * Usage:
 *   [$php, $script] = AgentTestExecutable::command();
 *   // proc_open([$php, $script, 'agent', '--controller', ...], ...)
 *
 *   $path = AgentTestExecutable::path();
 *   // Returns the absolute path to bin/console or the PHAR.
 */
final class AgentTestExecutable
{
    /**
     * @return string[] Two-element command array: [PHP_BINARY, <executable>]
     */
    public static function command(): array
    {
        $binaryPath = self::resolveBinaryPath();

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
}
