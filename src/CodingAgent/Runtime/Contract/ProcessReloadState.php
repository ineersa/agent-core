<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\Contract;

/**
 * Process-global relay for a pending process-reload intent.
 *
 * Static on purpose: the intent is produced by TUI services inside the
 * old container and consumed by bin/console AFTER the old kernel/container
 * have been torn down and a fresh one booted — no object channel survives
 * that rebuild. Env vars were rejected as the carrier because they leak
 * into spawned controller/consumer subprocesses.
 *
 * ponytail: single-process static relay; a second writer (e.g. a separate
 * reload orchestrator process) would need a file/queue channel instead.
 *
 * @see ProcessReloadIntentDTO
 */
final class ProcessReloadState
{
    /** Exit code signalling bin/console's outer loop to re-bootstrap. */
    public const int EXIT_CODE = 75;

    private static ?ProcessReloadIntentDTO $pending = null;

    private function __construct()
    {
    }

    public static function set(ProcessReloadIntentDTO $intent): void
    {
        self::$pending = $intent;
    }

    public static function consume(): ?ProcessReloadIntentDTO
    {
        $pending = self::$pending;
        self::$pending = null;

        return $pending;
    }
}
