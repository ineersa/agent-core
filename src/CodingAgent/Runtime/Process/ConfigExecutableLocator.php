<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\Process;

/**
 * Resolves the agent executable from an explicit HATFIELD_BINARY_PATH env override.
 *
 * When set, the value can be an absolute path or a relative path resolved
 * against the runtime cwd. The binary must exist and be readable at the
 * resolved path.
 *
 * Use cases:
 *   - Tests: HATFIELD_BINARY_PATH=var/tmp/phar/hatfield.phar
 *   - Custom install: HATFIELD_BINARY_PATH=/opt/hatfield/hatfield.phar
 *
 * Falls back through ChainExecutableLocator when the env var is not set.
 */
final class ConfigExecutableLocator implements AppExecutableLocator
{
    public function __construct(
        private readonly string $runtimeCwd = '',
    ) {
    }

    public function command(): array
    {
        $path = $this->resolve();

        // Fused PHP-micro / static native binary: relaunch as a single argv
        // element so controller/Messenger children do not try to invoke
        // "php <self>" with a separate (often empty) PHP interpreter.
        if (self::isFusedNativeExecutable($path)) {
            return [$path];
        }

        return [\PHP_BINARY, $path];
    }

    public function path(): string
    {
        return $this->resolve();
    }

    /**
     * True when $path is the fused native self.
     *
     * Fused PHP-micro reports empty PHP_BINARY on macOS (and likely all static
     * targets), so empty PHP_BINARY is treated as native self. When PHP_BINARY
     * is set, same-path/inode detection still applies for hosts that point it
     * at the fused binary.
     */
    private static function isFusedNativeExecutable(string $path): bool
    {
        if (!is_file($path)) {
            return false;
        }

        // constant() so PHPStan cannot treat PHP_BINARY as always non-empty; fused
        // PHP-micro leaves it empty at runtime and the artifact is self-executing.
        $phpBinary = \defined('PHP_BINARY') ? (string) \constant('PHP_BINARY') : '';
        if ('' === $phpBinary) {
            return true;
        }

        $resolvedPath = realpath($path);
        $resolvedPhp = realpath($phpBinary);
        if (false === $resolvedPath || false === $resolvedPhp) {
            return $path === $phpBinary;
        }

        return $resolvedPath === $resolvedPhp;
    }

    /**
     * @throws \RuntimeException when HATFIELD_BINARY_PATH is not set, empty,
     *                           or the resolved path does not exist
     */
    private function resolve(): string
    {
        $binaryPath = getenv('HATFIELD_BINARY_PATH');
        if (false === $binaryPath || '' === $binaryPath) {
            throw new \RuntimeException('HATFIELD_BINARY_PATH is not set. Set it to an absolute or runtime-cwd-relative path to an agent executable.');
        }

        // Resolve relative paths against the runtime cwd.
        if (!str_starts_with($binaryPath, '/')) {
            $cwd = '' !== $this->runtimeCwd ? $this->runtimeCwd : ((false !== ($c = getcwd())) ? $c : '');
            if ('' !== $cwd) {
                $binaryPath = $cwd.'/'.$binaryPath;
            }
        }

        if (!is_file($binaryPath)) {
            throw new \RuntimeException(\sprintf('HATFIELD_BINARY_PATH resolved to a non-existent file: %s', $binaryPath));
        }

        if (!is_readable($binaryPath)) {
            throw new \RuntimeException(\sprintf('HATFIELD_BINARY_PATH resolved to a non-readable file: %s', $binaryPath));
        }

        return $binaryPath;
    }
}
