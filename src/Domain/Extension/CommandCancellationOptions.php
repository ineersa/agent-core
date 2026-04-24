<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Domain\Extension;

final readonly class CommandCancellationOptions
{
    public function __construct(
        public bool $safe = false,
    ) {
    }
}
