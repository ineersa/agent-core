<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Application\Bash;

/**
 * Deterministic native Bash path identity from the full invocation tuple.
 *
 * Paths live in existing status/log columns; no new SQL fields.
 */
final class NativeBashIdentity
{
    public static function prefix(
        string $runId,
        int $turnNo,
        string $stepId,
        string $toolCallId,
        int $attempt,
        string $idempotencyKey,
    ): string {
        return hash(
            'sha256',
            \sprintf('%s|%d|%s|%s|%d|%s', $runId, $turnNo, $stepId, $toolCallId, $attempt, $idempotencyKey),
        );
    }

    public static function matchesStatusPath(string $statusPath, string $expectedPrefix): bool
    {
        return basename($statusPath) === $expectedPrefix.'.status';
    }

    public static function matchesLogPath(string $logPath, string $expectedPrefix): bool
    {
        return basename($logPath) === $expectedPrefix.'.log';
    }
}
