<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Contract\Tool;

/**
 * Thrown when a cooperative cancellation checkpoint detects that the
 * run has been cancelled.
 *
 * ToolExecutor catches this exception and returns a structured
 * cancellation result (cancelled=true) rather than a generic tool error.
 */
final class ToolCancelledException extends \RuntimeException
{
    public function __construct(
        string $message = 'Tool execution cancelled by request.',
        int $code = 0,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $code, $previous);
    }
}
