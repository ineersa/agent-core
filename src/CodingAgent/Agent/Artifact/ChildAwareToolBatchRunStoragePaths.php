<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Artifact;

use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Ineersa\CodingAgent\Session\SessionAgentArtifactPathResolver;
use Ineersa\CodingAgent\Session\ToolBatchRunStoragePathsInterface;

final class ChildAwareToolBatchRunStoragePaths implements ToolBatchRunStoragePathsInterface
{
    private const string RUNTIME_SUBDIR = 'runtime/tool-batches';

    /** @var array<string, array{string, string}> Original owner and path survive registry deletion. */
    private array $scopes = [];

    public function __construct(
        private readonly HatfieldSessionStore $hatfieldSessionStore,
        private readonly AgentChildRunDirectory $childRunDirectory,
        private readonly SessionAgentArtifactPathResolver $artifactPathResolver,
    ) {
    }

    public function resolveToolBatchesDirectory(string $runId): string
    {
        if (isset($this->scopes[$runId])) {
            return $this->scopes[$runId][1];
        }
        $entry = $this->childRunDirectory->locate($runId);
        if (null !== $entry) {
            $this->resolveToolBatchesDirectory($entry->parentRunId);
            $this->scopes[$runId] = [$this->scopes[$entry->parentRunId][0], $this->artifactPathResolver->resolveArtifactDir($entry->parentRunId, $entry->artifactId).'/'.self::RUNTIME_SUBDIR];
        } else {
            $this->scopes[$runId] = [$runId, $this->hatfieldSessionStore->resolveSessionsBasePath().'/'.$runId.'/'.self::RUNTIME_SUBDIR];
        }

        return $this->scopes[$runId][1];
    }

    public function withStorage(string $runId, callable $write): mixed
    {
        $this->resolveToolBatchesDirectory($runId);

        return $this->hatfieldSessionStore->withStorage($this->scopes[$runId][0], $write);
    }
}
