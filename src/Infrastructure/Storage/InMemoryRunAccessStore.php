<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Infrastructure\Storage;

use Ineersa\AgentCore\Contract\RunAccessStoreInterface;
use Ineersa\AgentCore\Domain\Run\RunAccessScope;

/**
 * InMemoryRunAccessStore provides an in-memory implementation for persisting and retrieving RunAccessScope entities keyed by run ID. It serves as a transient storage layer for run access metadata within the agent core infrastructure.
 */
final class InMemoryRunAccessStore implements RunAccessStoreInterface
{
    /** @var array<string, RunAccessScope> */
    private array $scopes = [];

    public function save(RunAccessScope $scope): void
    {
        $this->scopes[$scope->runId] = $scope;
    }

    public function get(string $runId): ?RunAccessScope
    {
        return $this->scopes[$runId] ?? null;
    }

    public function touch(string $runId, ?\DateTimeImmutable $updatedAt = null): void
    {
        $scope = $this->scopes[$runId] ?? null;
        if (null === $scope) {
            return;
        }

        $this->scopes[$runId] = $scope->withUpdatedAt($updatedAt);
    }
}
