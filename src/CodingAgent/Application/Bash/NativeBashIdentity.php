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
}
