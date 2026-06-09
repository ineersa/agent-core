<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\CLI;

/**
 * Semantically distinguishes a lock-held condition from a real
 * build failure.
 *
 * Callers that spawn {@see CompletionFileIndexRefreshCommand}
 * or use {@see FileMentionIndexBuilder} directly can catch this
 * exception to treat lock contention as a successful no-op while
 * surface actual scan/write errors as failures.
 */
final class FileMentionIndexLockHeldException extends \RuntimeException
{
    public function __construct(string $message = 'File mention index build already in progress (lock held).', ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
