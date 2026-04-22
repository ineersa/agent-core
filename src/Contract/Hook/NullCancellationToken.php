<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Contract\Hook;

/**
 * Non-cancelable sentinel implementing the cancellation token contract for scenarios that do not require abort support.
 */
final readonly class NullCancellationToken implements CancellationTokenInterface
{
    public function isCancellationRequested(): bool
    {
        return false;
    }
}
