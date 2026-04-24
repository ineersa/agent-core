<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Infrastructure\Storage;

use Ineersa\AgentCore\Contract\PromptStateStoreInterface;
use Ineersa\AgentCore\Domain\Run\PromptState;

final class HotPromptStateStore implements PromptStateStoreInterface
{
    /** @var array<string, PromptState> */
    private array $states = [];

    public function get(string $runId): ?PromptState
    {
        return $this->states[$runId] ?? null;
    }

    public function save(string $runId, PromptState $state): void
    {
        if ($state->runId !== $runId) {
            throw new \InvalidArgumentException('PromptState runId must match persisted runId.');
        }

        if (isset($this->states[$runId])) {
            $this->delete($runId);
        }

        $this->states[$runId] = $state->withUpdatedAt(new \DateTimeImmutable());
    }

    public function delete(string $runId): void
    {
        unset($this->states[$runId]);
    }
}
