<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Infrastructure\Storage;

use Ineersa\AgentCore\Contract\PromptStateStoreInterface;

final class HotPromptStateStore implements PromptStateStoreInterface
{
    /** @var array<string, array<string, mixed>> */
    private array $states = [];

    public function get(string $runId): ?array
    {
        return $this->states[$runId] ?? null;
    }

    public function save(string $runId, array $state): void
    {
        if (null !== $this->get($runId)) {
            $this->delete($runId);
        }

        $normalizedState = $state;
        $updatedAt = new \DateTimeImmutable();
        $normalizedState['updated_at'] = $updatedAt->format(\DATE_ATOM);

        $this->states[$runId] = $normalizedState;
    }

    public function delete(string $runId): void
    {
        unset($this->states[$runId]);
    }
}
