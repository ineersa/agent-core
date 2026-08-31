<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Session;

final class SessionToolBatchStoreException extends \RuntimeException
{
    public function __construct(
        string $message,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
