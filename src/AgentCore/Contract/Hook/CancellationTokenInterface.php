<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Contract\Hook;

interface CancellationTokenInterface
{
    public function isCancellationRequested(): bool;
}
