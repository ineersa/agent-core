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
 * Works in all environments where the code is running as a PHAR, including
 * Box 4.x-compiled PHARs that use an internal PHAR alias (where
 * Phar::running() returns empty). Falls back to extracting the physical
 * PHAR path from __FILE__ when standard detection fails.
 *
 * When not inside a PHAR (e.g. source checkout), throws an informative
 * exception so the caller can fall back to another locator in the chain.
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
        // 1. Standard PHAR detection — works for most PHAR archives including
        //    manually created ones and PHARs with a proper filesystem alias.
        $pharPath = \Phar::running(false);
        if ('' !== $pharPath) {
            return $pharPath;
        }

        // 2. Box 4.x+ PHAR detection via __FILE__ prefix.
        //    Box uses an internal auto-generated alias (Phar::mapPhar(...) with
        //    an alias name, not a filesystem path). When the PHAR stub requires
        //    the inner bin/console, __FILE__ becomes a phar:// URL like:
        //      phar://box-auto-generated-alias-XXXX/bin/console
        //    Phar::running() returns empty in this context because the PHP
        //    recognised PHAR stream does not expose the physical PHAR path.
        //
        //    We construct a Phar object from the phar:// URL, which resolves
        //    back to the physical PHAR file on disk.
        if (str_starts_with(__FILE__, 'phar://')) {
            $previous = null;
            try {
                $phar = new \Phar(__FILE__);
                $physicalPath = $phar->getPath();
                if ('' !== $physicalPath && is_file($physicalPath)) {
                    return $physicalPath;
                }

                throw new \RuntimeException(\sprintf('Phar path "%s" from %s does not exist or is not a file.', $physicalPath, __FILE__));
            } catch (\Throwable $e) {
                $previous = $e;
            }

            throw new \RuntimeException(\sprintf('Running inside a PHAR (%s) but unable to resolve the physical PHAR path. Phar::running() returned empty (Box auto-generated alias), and constructing a Phar object from __FILE__ failed: %s', __FILE__, $previous->getMessage()), 0, $previous);
        }

        throw new \RuntimeException('PharExecutableLocator requires running inside a PHAR. Use SourceTreeExecutableLocator or ChainExecutableLocator for source-checkout environments.');
    }
}
