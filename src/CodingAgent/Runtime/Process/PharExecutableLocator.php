<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\Process;

/**
 * Resolves the agent executable for a PHAR distribution.
 *
 * Uses Phar::running() to detect the current PHAR path and returns the
 * command array [PHP_BINARY, <phar-path>]. This allows subprocess spawning
 * (controller, messenger consumers) to self-reference the same PHAR.
 *
 * Works in all environments where the code is running as a PHAR. When not
 * inside a PHAR (e.g. source checkout), throws an informative exception
 * so the caller can fall back to another locator.
 */
final class PharExecutableLocator implements AppExecutableLocator
{
    public function command(): array
    {
        return [\PHP_BINARY, $this->doResolve()];
    }

    public function path(): string
    {
        return $this->doResolve();
    }

    /**
     * @throws \RuntimeException when not running inside a PHAR
     */
    private function doResolve(): string
    {
        $pharPath = \Phar::running(false);
        if ('' !== $pharPath) {
            return $pharPath;
        }

        throw new \RuntimeException('PharExecutableLocator requires running inside a PHAR. Use SourceTreeExecutableLocator or ChainExecutableLocator for source-checkout environments.');
    }
}
