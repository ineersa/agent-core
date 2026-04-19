<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Contract\Hook;

/**
 * Defines the contract for a cancellation token used to signal intent to abort long-running or asynchronous hook operations. It provides a mechanism for cooperative cancellation without requiring external state management.
 */
interface CancellationTokenInterface
{
    /**
     * Checks if cancellation has been requested.
     */
    public function isCancellationRequested(): bool;
}
