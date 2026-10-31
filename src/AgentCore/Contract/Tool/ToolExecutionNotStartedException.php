<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Contract\Tool;

/**
 * Typed pre-handler / pre-launch failure: the tool handler was never invoked.
 *
 * Owner settlement uses this as unused-preparation evidence. Do not throw after
 * AgentRunner.start/followUp or after the toolbox handler may have run.
 */
final class ToolExecutionNotStartedException extends ToolCallException
{
    public function __construct(string $error, ?\Throwable $previous = null)
    {
        parent::__construct($error, retryable: false, hint: null, previous: $previous);
    }
}
