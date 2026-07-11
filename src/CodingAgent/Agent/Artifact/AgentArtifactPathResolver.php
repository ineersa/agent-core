<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Artifact;

use Ineersa\CodingAgent\Session\SessionAgentArtifactPathResolver;

/**
 * AppAgent facade for parent-scoped agent artifact paths.
 *
 * All path construction and validation delegate to
 * {@see SessionAgentArtifactPathResolver} (canonical AppSession implementation).
 *
 * Does NOT create directories — callers are responsible for mkdir when
 * writing files.
 */
final class AgentArtifactPathResolver
{
    /** Directory permission for artifact storage directories. */
    public const int DIR_PERMISSIONS = SessionAgentArtifactPathResolver::DIR_PERMISSIONS;

    /** File permission for artifact storage files. */
    public const int FILE_PERMISSIONS = SessionAgentArtifactPathResolver::FILE_PERMISSIONS;

    public function __construct(
        private readonly SessionAgentArtifactPathResolver $canonical,
    ) {
    }

    public function resolveArtifactsBasePath(string $parentRunId): string
    {
        return $this->canonical->resolveArtifactsBasePath($parentRunId);
    }

    public function resolveArtifactDir(string $parentRunId, string $artifactId): string
    {
        return $this->canonical->resolveArtifactDir($parentRunId, $artifactId);
    }

    public function registryPath(string $parentRunId): string
    {
        return $this->canonical->registryPath($parentRunId);
    }

    public function absolutePath(string $parentRunId, string $relative): string
    {
        return $this->canonical->absolutePath($parentRunId, $relative);
    }

    public function statePath(string $parentRunId, string $artifactId): string
    {
        return $this->canonical->statePath($parentRunId, $artifactId);
    }

    public function eventsPath(string $parentRunId, string $artifactId): string
    {
        return $this->canonical->eventsPath($parentRunId, $artifactId);
    }

    public function validatePathComponent(string $value, string $field): void
    {
        $this->canonical->validatePathComponent($value, $field);
    }
}
