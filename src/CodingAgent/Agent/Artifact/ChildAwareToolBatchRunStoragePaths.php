<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Artifact;

use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Ineersa\CodingAgent\Session\ToolBatchRunStoragePathsInterface;

final class ChildAwareToolBatchRunStoragePaths implements ToolBatchRunStoragePathsInterface
{
    private const string RUNTIME_SUBDIR = 'runtime/tool-batches';

    public function __construct(
        private readonly HatfieldSessionStore $hatfieldSessionStore,
        private readonly AgentChildRunDirectory $childRunDirectory,
        private readonly AgentArtifactPathResolver $artifactPathResolver,
    ) {
    }

    public function resolveToolBatchesDirectory(string $runId): string
    {
        $entry = $this->childRunDirectory->locate($runId);
        if (null !== $entry) {
            return $this->artifactPathResolver->resolveArtifactDir($entry->parentRunId, $entry->artifactId).'/'.self::RUNTIME_SUBDIR;
        }

        return $this->hatfieldSessionStore->resolveSessionsBasePath().'/'.$runId.'/'.self::RUNTIME_SUBDIR;
    }
}
