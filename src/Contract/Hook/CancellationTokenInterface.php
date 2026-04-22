<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Contract\Hook;

/**
 * Signals cooperative cancellation for long-running or asynchronous hook operations.
 */
interface CancellationTokenInterface
{
    public function isCancellationRequested(): bool;
}
