<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Contract\Extension;

/**
 * Defines the contract for command handlers within the AgentCore extension system, enabling type-safe dispatch and transformation of command payloads. It ensures that handlers can validate command kinds and safely map execution contexts to structured results.
 */
interface CommandHandlerInterface
{
    /**
     * Checks if the handler supports the specified command kind.
     */
    public function supports(string $kind): bool;

    /**
     * Verifies if the command kind supports safe cancellation semantics.
     */
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
