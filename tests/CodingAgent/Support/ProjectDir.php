<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Support;

use Ineersa\CodingAgent\Kernel;

/**
 * Resolves the project root directory for tests.
 *
 * Uses the CodingAgent Kernel to walk up from its own location
 * until it finds composer.json — the authoritative definition of
 * the project root. This is robust against test file moves and
 * does not require the kernel to be booted.
 *
 * Usage:
 *   ProjectDir::get();   // '/home/ineersa/projects/agent-core'
 */
final class ProjectDir
{
    private static ?string $dir = null;

    public static function get(): string
    {
        if (null === self::$dir) {
            // Instantiate the kernel without booting — getProjectDir()
            // walks up from Kernel.php until it finds composer.json.
            self::$dir = (new Kernel('test', false))->getProjectDir();
        }

        return self::$dir;
    }
}
