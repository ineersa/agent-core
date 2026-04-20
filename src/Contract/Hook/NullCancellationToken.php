<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Contract\Hook;

/**
 * NullCancellationToken implements the cancellation token contract by providing a non-cancelable instance. It serves as a safe default for scenarios where cancellation support is not required or applicable.
 */
final readonly class NullCancellationToken implements CancellationTokenInterface
{
    public function isCancellationRequested(): bool
    {
        return false;
    }
}
