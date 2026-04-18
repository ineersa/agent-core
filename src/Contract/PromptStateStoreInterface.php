<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Contract;

interface PromptStateStoreInterface
{
    /**
     * @return array<string, mixed>|null
     */
    public function get(string $runId): ?array;

    /**
     * @param array<string, mixed> $state
     */
    public function save(string $runId, array $state): void;

    public function delete(string $runId): void;
}
