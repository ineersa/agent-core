<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tool\Edit\Exception;

use Ineersa\AgentCore\Contract\Tool\ToolCallException;

final class StalePatchHunkException extends ToolCallException
{
    public function __construct(
        string $error,
        public readonly int $failedLine,
        string $hint,
    ) {
        parent::__construct($error, retryable: true, hint: $hint);
    }
}
