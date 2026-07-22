<?php

declare(strict_types=1);

namespace Ineersa\Hatfield\ExtensionApi\Session;

/**
 * Stable public failure for recovery/compaction event reads.
 *
 * Does not expose internal storage paths or raw filesystem details.
 */
final class SessionEventReaderException extends \RuntimeException
{
    public static function readFailed(string $runId, string $safeMessage): self
    {
        return new self(\sprintf('Failed to read session events for run "%s": %s', $runId, $safeMessage));
    }
}
