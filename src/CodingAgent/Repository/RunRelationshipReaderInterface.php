<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Repository;

/**
 * Hot-path child/parent classification from run_operational_state.
 *
 * Production boundary across launch/safety/policy consumers. Never opens
 * EventStore or session/artifact filesystem indexes.
 *
 * Unknown operational identity is not treated as top-level: callers that must
 * distinguish top-level vs unknown use {@see requireKnownTopLevel()} or the
 * fail-closed boolean/parent readers below.
 */
interface RunRelationshipReaderInterface
{
    /**
     * True only for a known operational child row.
     *
     * Missing rows throw — never silently look like a parent/top-level run.
     */
    public function isAgentChild(string $runId): bool;

    /**
     * Immediate parent run id for a known operational child row.
     *
     * Returns null for a known top-level row. Missing rows throw.
     */
    public function readParentRunId(string $runId): ?string;

    /**
     * Fail closed for nested launch/depth gates: unknown and child rows both block.
     */
    public function requireKnownTopLevel(string $runId): void;
}
