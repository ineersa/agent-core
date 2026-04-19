<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Application\Handler;

/**
 * Ensures message idempotency by tracking processed execution keys within specific scopes and run contexts. It prevents duplicate processing of identical idempotency keys for the same logical operation.
 */
final class MessageIdempotencyService
{
    /** @var array<string, true> */
    private array $handled = [];

    /**
     * Checks if an idempotency key has already been processed for the given scope and run ID.
     */
    public function wasHandled(string $scope, string $runId, string $idempotencyKey): bool
    {
        return isset($this->handled[$this->index($scope, $runId, $idempotencyKey)]);
    }

    /**
     * Records an idempotency key as processed for the specified scope and run ID.
     */
    public function markHandled(string $scope, string $runId, string $idempotencyKey): void
    {
        $this->handled[$this->index($scope, $runId, $idempotencyKey)] = true;
    }

    /**
     * Generates a composite index key from scope, run ID, and idempotency key.
     */
    private function index(string $scope, string $runId, string $idempotencyKey): string
    {
        return \sprintf('%s|%s|%s', $scope, $runId, $idempotencyKey);
    }
}
