<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Session\RunState;

use Ineersa\AgentCore\Domain\Run\RunState;

/**
 * Shared disposable current RunState projection.
 *
 * Ordinary {@see get()} must not scan the event archive. Cold publish uses
 * {@see initialize()} / {@see remember()}. Freshness is committed sequence
 * identity; TTL only bounds cleanup.
 */
interface RunStateStoreInterface
{
    /**
     * Return the current shared state. Missing/expired entries fail closed —
     * callers must initialize via startup/new-run/recovery, not trigger a scan.
     */
    public function get(string $runId): RunState;

    /**
     * Startup/recovery bootstrap lookup. Returns withdrawn (not-ready) state too.
     * Corrupt entries throw; only absence returns null.
     */
    public function find(string $runId): ?RunState;

    /**
     * Publish authoritative current state. Refuses to regress lastSeq.
     */
    public function remember(RunState $state): void;

    /**
     * Publish an initial state only when no shared projection exists yet.
     */
    public function initialize(RunState $state): void;

    public function invalidate(string $runId): void;

    /**
     * Mark the current projection not-ready before a canonical append that may be
     * interrupted. Ordinary get() rejects not-ready entries. Missing entries are a no-op.
     */
    public function withdrawForCommit(string $runId): void;

    /**
     * True when a ready shared projection exists for the run.
     * Absent and not-ready entries return false. Corrupt entries throw.
     */
    public function isReady(string $runId): bool;
}
