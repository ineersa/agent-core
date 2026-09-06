<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Contract\Hook;

interface CancellationTokenInterface
{
    /**
     * Operational poll: result may change between calls as cancel status becomes visible.
     *
     * @phpstan-impure
     */
    public function isCancellationRequested(): bool;
}
