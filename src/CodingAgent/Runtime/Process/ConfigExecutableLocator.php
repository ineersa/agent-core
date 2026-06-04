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
 *   - Tests: HATFIELD_BINARY_PATH=/tmp/bin/hatfield.phar
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
        return [\PHP_BINARY, $this->resolve()];
    }

    public function path(): string
    {
        return $this->resolve();
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
            $cwd = '' !== $this->runtimeCwd ? $this->runtimeCwd : (getcwd() ?: '');
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
