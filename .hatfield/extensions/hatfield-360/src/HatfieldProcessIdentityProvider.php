<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\Hatfield360;

use Symfony\Component\Uid\Uuid;

final class HatfieldProcessIdentityProvider
{
    private static ?string $instanceId = null;

    public static function register(): ?StatusClient
    {
        if ('Linux' !== \PHP_OS_FAMILY || false !== getenv('H360_STATUS_TOKEN') || false !== getenv('H360_STATUS_LAUNCH_ID')) {
            return null;
        }

        $pid = getmypid();
        $namespace = @fileinode('/proc/self/ns/pid');
        $stat = @file_get_contents('/proc/self/stat', length: 8192);
        $end = false === $stat ? false : strrpos($stat, ')');
        if (false === $pid || false === $namespace || false === $stat || false === $end) {
            return null;
        }
        // The command name can itself contain spaces and parentheses. The tail
        // begins at field 3, so field 22 is its zero-based field 19.
        $ticks = explode(' ', substr($stat, $end + 2))[19] ?? '';

        return StatusClient::registerManual('hatfield', self::$instanceId ??= (string) Uuid::v4(), $pid, (string) $namespace, $ticks);
    }
}
