<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\Controller;

/**
 * Stable flock resource names for one project CWD + session.
 *
 * Controller ownership and the sole run_control worker use distinct resources so
 * the controller can supervise consumers while the worker holds exclusive claim
 * ownership for the session queue.
 */
final class SessionScopedLockResource
{
    public static function controllerOwner(string $runtimeCwd, string $sessionId): string
    {
        return self::hashed('hatfield.controller.session.', $runtimeCwd, $sessionId);
    }

    public static function runControlWorker(string $runtimeCwd, string $sessionId): string
    {
        return self::hashed('hatfield.run_control.worker.', $runtimeCwd, $sessionId);
    }

    private static function hashed(string $prefix, string $runtimeCwd, string $sessionId): string
    {
        $canonicalCwd = realpath($runtimeCwd);
        if (false === $canonicalCwd) {
            $canonicalCwd = $runtimeCwd;
        }

        return $prefix.hash('sha256', $canonicalCwd."\0".$sessionId);
    }
}
