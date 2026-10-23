<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Session;

/**
 * Resolves filesystem directory for transient tool-batch snapshots for a run id.
 */
interface ToolBatchRunStoragePathsInterface
{
    public function resolveToolBatchesDirectory(string $runId): string;

    /** Serialize a short publication with permanent deletion of its owning session. */
    public function withStorageWriteLock(string $directory, callable $write): mixed;
}
