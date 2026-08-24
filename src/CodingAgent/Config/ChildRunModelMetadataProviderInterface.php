<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Config;

/**
 * AppConfig-side port for a child run's execution model/reasoning.
 *
 * Agent child runs (fork/subagent) carry their definition model and reasoning
 * in the RunStarted event metadata.  Ordinary sessions have no such metadata
 * and resolve from the mutable hatfield_session row.  {@see SessionAwareModelResolver}
 * (AppConfig layer) uses this port so it never depends on AppAgent/AppExtension
 * internals; {@see \Ineersa\CodingAgent\Agent\Execution\SubagentRunMetadataReader}
 * implements it (AppAgent → AppConfig is a deptrac-approved edge).
 */
interface ChildRunModelMetadataProviderInterface
{
    /**
     * Returns the child run's execution model/reasoning snapshot, or null when
     * the run is not an agent child (or has no RunStarted metadata).
     */
    public function childRunModel(string $runId): ?ChildRunModelSnapshot;
}
