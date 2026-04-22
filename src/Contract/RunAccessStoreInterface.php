<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Contract;

use Ineersa\AgentCore\Domain\Run\RunAccessScope;

interface RunAccessStoreInterface
{
    public function save(RunAccessScope $scope): void;

    public function get(string $runId): ?RunAccessScope;

    public function touch(string $runId, ?\DateTimeImmutable $updatedAt = null): void;
}
