<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Application\Handler;

use Ineersa\AgentCore\Contract\IdempotencyStoreInterface;

/**
 * Persistent idempotency check for bus messages.
 *
 * Delegates to IdempotencyStoreInterface for cross-process safe,
 * crash-robust tracking of which messages have been handled.
 *
 * Replaces the previous in-memory array implementation that lost
 * state on process restart and was unsafe across consumers.
 */
final readonly class MessageIdempotencyService
{
    public function __construct(
        private IdempotencyStoreInterface $store,
    ) {
    }

    public function wasHandled(string $scope, string $runId, string $idempotencyKey): bool
    {
        return $this->store->isHandled($scope, $runId, $idempotencyKey);
    }

    public function markHandled(string $scope, string $runId, string $idempotencyKey): void
    {
        $this->store->markHandled($scope, $runId, $idempotencyKey);
    }
}
