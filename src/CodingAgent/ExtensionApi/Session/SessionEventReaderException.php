<?php

declare(strict_types=1);

namespace Ineersa\Hatfield\ExtensionApi\Session;

/**
 * Stable public error contract for canonical session event reads.
 */
final class SessionEventReaderException extends \RuntimeException
{
    public const string CODE_MISSING_SESSION = 'missing_session';
    public const string CODE_INVALID_RANGE = 'invalid_range';
    public const string CODE_READ_FAILED = 'read_failed';

    public function __construct(
        public readonly string $errorCode,
        string $message,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public static function missingSession(string $runId): self
    {
        return new self(
            self::CODE_MISSING_SESSION,
            \sprintf('Session/run "%s" was not found.', $runId),
        );
    }

    public static function invalidRange(string $runId, int $startSeq, int $endSeq): self
    {
        return new self(
            self::CODE_INVALID_RANGE,
            \sprintf('Invalid inclusive event range for run "%s": start=%d end=%d.', $runId, $startSeq, $endSeq),
        );
    }

    public static function readFailed(string $runId, string $safeMessage, ?\Throwable $previous = null): self
    {
        return new self(
            self::CODE_READ_FAILED,
            \sprintf('Failed to read events for run "%s": %s', $runId, $safeMessage),
            $previous,
        );
    }
}
