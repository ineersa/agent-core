<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Utility;

/**
 * Failure of an {@see AtomicFileWriter} operation.
 *
 * Carries the failing stage and the temporary path (when one was created)
 * so call sites can translate to their own exception contract without
 * parsing messages. Stages: mkdir | write | chmod | rename.
 */
final class AtomicFileWriterException extends \RuntimeException
{
    public function __construct(
        public readonly string $stage,
        public readonly ?string $tempPath,
        string $message,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
