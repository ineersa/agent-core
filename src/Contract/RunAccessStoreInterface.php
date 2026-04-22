<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Contract;

use Ineersa\AgentCore\Domain\Run\RunAccessScope;

/**
 * Persists access scopes per run with atomic save and timestamp-based access tracking.
 */
interface RunAccessStoreInterface
{
    public function save(RunAccessScope $scope): void;

    public function get(string $runId): ?RunAccessScope;

    public function touch(string $runId, ?\DateTimeImmutable $updatedAt = null): void;
}
