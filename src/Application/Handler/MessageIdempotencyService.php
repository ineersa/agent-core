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

    public function wasHandled(string $scope, string $runId, string $idempotencyKey): bool
    {
        return isset($this->handled[$this->index($scope, $runId, $idempotencyKey)]);
    }

    public function markHandled(string $scope, string $runId, string $idempotencyKey): void
    {
        $this->handled[$this->index($scope, $runId, $idempotencyKey)] = true;
    }

    private function index(string $scope, string $runId, string $idempotencyKey): string
    {
        return \sprintf('%s|%s|%s', $scope, $runId, $idempotencyKey);
    }
}
