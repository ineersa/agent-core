<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Utility;

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
