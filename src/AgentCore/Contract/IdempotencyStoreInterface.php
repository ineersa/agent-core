<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Contract;

/**
 * Cross-process persistent idempotency store.
 *
 * Tracks which messages have been handled to guarantee at-most-once
 * processing in the multi-process async topology.
 *
 * Implementations must be thread-safe and process-safe. The store is
 * keyed by (scope, runId, idempotencyKey), where scope identifies the
 * processing stage (e.g. "command.start", "result.llm") and the key
 * is a unique message-identifying hash.
 */
interface IdempotencyStoreInterface
{
    /**
     * Returns true if the given (scope, runId, idempotencyKey) tuple
     * has been marked as handled.
     */
    public function isHandled(string $scope, string $runId, string $idempotencyKey): bool;

    /**
     * Marks the given (scope, runId, idempotencyKey) tuple as handled.
     *
     * Must be idempotent: marking an already-handled key must not throw.
     * Must be durable: a process restart must not lose the mark.
     */
    public function markHandled(string $scope, string $runId, string $idempotencyKey): void;
}
