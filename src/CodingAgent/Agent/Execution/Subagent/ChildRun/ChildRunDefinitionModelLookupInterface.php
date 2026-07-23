<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Execution\Subagent\ChildRun;

/**
 * Lookup for durable deferred-subagent child definition models.
 *
 * Child run IDs are UUIDs and must never be cast into normal
 * hatfield_session integer lookups.
 */
interface ChildRunDefinitionModelLookupInterface
{
    /**
     * @return string|null Non-empty provider/model reference, or null when the child row is absent
     */
    public function findDefinitionModelByChildRunId(string $childRunId): ?string;
}
