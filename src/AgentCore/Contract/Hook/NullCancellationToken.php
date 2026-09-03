<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Contract\Hook;

final readonly class NullCancellationToken implements CancellationTokenInterface
{
    /**
     * Constant no-op token: never cancelled, so this implementation is pure.
     *
     * @phpstan-pure
     */
    public function isCancellationRequested(): bool
    {
        return false;
    }
}
