<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Application\Handler;

/**
 * Persisted run event history contains duplicate sequence numbers.
 *
 * Raised by replay rebuilders and rewind preflight. Catch this type or
 * {@see RunStateReplayException}.
 */
final class RunStateDuplicateSequenceReplayException extends RunStateReplayException
{
    public function __construct(string $message, ?\Throwable $previous = null)
    {
        parent::__construct($message, $previous);
    }
}
