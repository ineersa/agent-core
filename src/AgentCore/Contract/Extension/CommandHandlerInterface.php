<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Contract\Extension;

use Ineersa\AgentCore\Domain\Extension\CommandCancellationOptions;

interface CommandHandlerInterface
{
    public function supports(string $kind): bool;

    public function supportsCancelSafe(string $kind): bool;

    /**
     * Maps a command payload and options to a structured result array for the given run and kind.
     *
     * @param array<string, mixed> $payload
     *
     * @return list<object>
     */
    public function map(string $runId, string $kind, array $payload, CommandCancellationOptions $cancellation): array;
}
