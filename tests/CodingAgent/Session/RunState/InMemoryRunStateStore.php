<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Session\RunState;

use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\CodingAgent\Session\RunState\RunStateStoreInterface;

/** Process-local RunState store for unit tests. */
final class InMemoryRunStateStore implements RunStateStoreInterface
{
    /** @var array<string, array{state: RunState, ready: bool}> */
    private array $entries = [];

    public function get(string $runId): RunState
    {
        if (!isset($this->entries[$runId])) {
            throw new \RuntimeException(\sprintf('Run state projection missing for run %s; initialize via startup/new-run/recovery before ordinary lookups.', $runId));
        }
        if (!$this->entries[$runId]['ready']) {
            throw new \RuntimeException(\sprintf('Run state projection for run %s is not ready; recovery required.', $runId));
        }

        return $this->entries[$runId]['state'];
    }

    public function find(string $runId): ?RunState
    {
        if (!isset($this->entries[$runId])) {
            return null;
        }

        return $this->entries[$runId]['state'];
    }

    public function remember(RunState $state): void
    {
        $cached = $this->entries[$state->runId]['state'] ?? null;
        if (null !== $cached && $cached->lastSeq > $state->lastSeq) {
            throw new \RuntimeException(\sprintf('Cannot publish run state for run %s at seq %d; shared projection is already at seq %d.', $state->runId, $state->lastSeq, $cached->lastSeq));
        }
        $this->entries[$state->runId] = ['state' => $state, 'ready' => true];
    }

    public function initialize(RunState $state): void
    {
        if (isset($this->entries[$state->runId])) {
            $cached = $this->entries[$state->runId]['state'];
            if ($this->entries[$state->runId]['ready'] && $cached->lastSeq === $state->lastSeq && $cached->status === $state->status) {
                return;
            }
            throw new \RuntimeException(\sprintf('Cannot initialize run state for run %s; shared projection already exists at seq %d.', $state->runId, $cached->lastSeq));
        }
        $this->entries[$state->runId] = ['state' => $state, 'ready' => true];
    }

    public function invalidate(string $runId): void
    {
        unset($this->entries[$runId]);
    }

    public function withdrawForCommit(string $runId): void
    {
        if (!isset($this->entries[$runId])) {
            return;
        }
        $this->entries[$runId]['ready'] = false;
    }

    public function isReady(string $runId): bool
    {
        return isset($this->entries[$runId]) && $this->entries[$runId]['ready'];
    }
}
