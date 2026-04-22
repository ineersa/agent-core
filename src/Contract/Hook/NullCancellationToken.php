<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Contract\Hook;

final readonly class NullCancellationToken implements CancellationTokenInterface
{
    public function isCancellationRequested(): bool
    {
        return false;
    }
}
