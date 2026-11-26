<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Domain\Extension;

/** @internal Legacy native Messenger payload type; no new commands use it. */
final readonly class CommandCancellationOptions
{
    public function __construct(
        public bool $safe = false,
    ) {
    }
}
