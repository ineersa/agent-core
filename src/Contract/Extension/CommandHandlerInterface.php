<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Contract\Extension;

interface CommandHandlerInterface
{
    public function supports(string $kind): bool;

    public function supportsCancelSafe(string $kind): bool;

    /**
     * Maps a command payload and options to a structured result array for the given run and kind.
     *
     * @param array<string, mixed>      $payload
     * @param array{cancel_safe?: bool} $options
     *
     * @return list<object>
     */
    public function map(string $runId, string $kind, array $payload, array $options = []): array;
}
