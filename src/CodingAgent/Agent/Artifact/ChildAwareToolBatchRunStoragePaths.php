<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Artifact;

use Ineersa\CodingAgent\Session\Exception\ToolPayloadException;
use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Ineersa\CodingAgent\Session\SessionAgentArtifactPathResolver;
use Ineersa\CodingAgent\Session\ToolBatchRunStoragePathsInterface;

final class ChildAwareToolBatchRunStoragePaths implements ToolBatchRunStoragePathsInterface
{
    private const string RUNTIME_SUBDIR = 'runtime/tool-batches';

    /** @var array<string, string> Resolved directories retain their original session authority. */
    private array $storageOwners = [];

    public function __construct(
        private readonly HatfieldSessionStore $hatfieldSessionStore,
        private readonly AgentChildRunDirectory $childRunDirectory,
        private readonly SessionAgentArtifactPathResolver $artifactPathResolver,
    ) {
    }

    public function resolveToolBatchesDirectory(string $runId): string
    {
        $entry = $this->childRunDirectory->locate($runId);
        $directory = null !== $entry
            ? $this->artifactPathResolver->resolveArtifactDir($entry->parentRunId, $entry->artifactId).'/'.self::RUNTIME_SUBDIR
            : $this->hatfieldSessionStore->resolveSessionsBasePath().'/'.$runId.'/'.self::RUNTIME_SUBDIR;
        $root = \dirname($directory);
        if (isset($this->storageOwners[$root])) {
            return $directory;
        }
        $owner = $runId;
        $seen = [];
        while (null !== $entry) {
            if (isset($seen[$owner])) {
                throw new ToolPayloadException('Tool storage ownership contains a cycle.');
            }
            $seen[$owner] = true;
            $owner = $entry->parentRunId;
            $entry = $this->childRunDirectory->locate($owner);
        }
        $this->storageOwners[$root] = $owner;

        return $directory;
    }

    public function withStorageWriteLock(string $directory, callable $write): mixed
    {
        $owner = $this->storageOwners[\dirname($directory)] ?? throw new ToolPayloadException('Tool storage has no resolved owner.');

        return $this->hatfieldSessionStore->withStorageWriteLock($owner, $write);
    }
}
