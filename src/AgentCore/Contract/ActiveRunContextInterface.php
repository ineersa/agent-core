<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Contract;

use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Domain\Run\RunState;

/**
 * Shared current run context with operational projection persistence.
 *
 * Ordinary {@see stateFor()} must not scan the event archive. New runs publish
 * via {@see initializeQueued()}; recovery publishes via {@see initialize()} /
 * {@see remember()}. Side-writers advance the shared projection with
 * {@see applyCommittedSuffix()}.
 */
interface ActiveRunContextInterface
{
    public function stateFor(string $runId): RunState;

    public function remember(RunState $state): void;

    /**
     * Publish empty queued state for a new run before the first handler.
     */
    public function initializeQueued(string $runId): RunState;

    /**
     * Publish an already-reconstructed authoritative state from startup/recovery.
     */
    public function initialize(RunState $state): void;

    /**
     * Apply already-committed side-writer events onto shared current state.
     *
     * @param list<RunEvent>                               $events
     * @param callable(RunState, list<RunEvent>): RunState $advance
     */
    public function applyCommittedSuffix(string $runId, array $events, callable $advance): RunState;

    public function invalidate(string $runId): void;

    /**
     * Mark shared state/history not-ready before a canonical append that may be
     * interrupted. Ordinary lookups reject not-ready projections until both are
     * published again after the append.
     */
    public function withdrawForCommit(string $runId): void;

    public function clear(): void;
}
