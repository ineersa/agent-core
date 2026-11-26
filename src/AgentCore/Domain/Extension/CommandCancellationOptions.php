<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Domain\Extension;

/** @internal Legacy native Messenger payload type; no new commands use it. */
final readonly class CommandCancellationOptions
{
    /** @param array<string, mixed> $data */
    public function __unserialize(array $data): void
    {
        // The native serializer still resolves this class when reading old pending rows.
        // The historical fields no longer affect command handling.
    }
}
