<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Session;

final class SessionToolBatchStoreException extends \RuntimeException
{
    /**
     * @param array<string, scalar|null> $context
     */
    public function __construct(
        string $message,
        public readonly array $context = [],
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
