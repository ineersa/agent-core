<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Contract;

use Ineersa\AgentCore\Domain\Run\RunAccessScope;

/**
 * Defines the contract for persisting and retrieving access scopes associated with specific runs. It enables atomic storage of scope data and supports timestamp updates for access tracking. This interface abstracts the underlying storage mechanism for run access control.
 */
interface RunAccessStoreInterface
{
    /**
     * persists a run access scope to storage.
     */
    public function save(RunAccessScope $scope): void;

    /**
     * retrieves a run access scope by run ID.
     */
    public function get(string $runId): ?RunAccessScope;

    /**
     * updates the access timestamp for a run.
     */
    public function touch(string $runId, ?\DateTimeImmutable $updatedAt = null): void;
}
