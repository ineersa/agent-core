<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Artifact;

use Ineersa\CodingAgent\Session\HatfieldSessionStore;

/**
 * Centralized path resolver for parent-scoped agent artifact storage.
 *
 * Responsibilities:
 *  - Resolve absolute filesystem paths for artifact files (registry,
 *    metadata, handoff, events, state) under the parent session directory.
 *  - Validate path components for traversal safety (empty, separators,
 *    exact '.' and '..' rejected).
 *  - Provide the canonical directory-permission constant.
 *
 * All path construction delegates to {@see AgentArtifactPathsDTO} for
 * relative paths; this resolver only adds the session base prefix.
 *
 * Does NOT create directories — callers are responsible for mkdir when
 * writing files.
 */
final class AgentArtifactPathResolver
{
    /** Directory permission for artifact storage directories. */
    public const DIR_PERMISSIONS = 0755;

    /** File permission for artifact storage files. */
    public const FILE_PERMISSIONS = 0644;

    /** Relative to parent session directory. */
    private const AGENTS_SUBDIR = 'artifacts/agents';

    private readonly string $sessionsBasePath;

    public function __construct(
        private readonly HatfieldSessionStore $hatfieldSessionStore,
    ) {
        $this->sessionsBasePath = $hatfieldSessionStore->resolveSessionsBasePath();
    }

    /**
     * Absolute path to the agents subdirectory for a parent session.
     *
     * Returns: <sessionsBase>/<parentRunId>/artifacts/agents/
     */
    public function resolveArtifactsBasePath(string $parentRunId): string
    {
        $this->validatePathComponent($parentRunId, 'parentRunId');

        return $this->sessionsBasePath.'/'.$parentRunId.'/'.self::AGENTS_SUBDIR;
    }

    /**
     * Absolute path to a single artifact directory.
     *
     * Returns: <sessionsBase>/<parentRunId>/artifacts/agents/<artifactId>/
     */
    public function resolveArtifactDir(string $parentRunId, string $artifactId): string
    {
        $this->validatePathComponent($parentRunId, 'parentRunId');
        $this->validatePathComponent($artifactId, 'artifactId');

        return $this->resolveArtifactsBasePath($parentRunId).'/'.$artifactId;
    }

    /**
     * Absolute path to the registry.json file.
     *
     * Returns: <sessionsBase>/<parentRunId>/artifacts/agents/registry.json
     */
    public function registryPath(string $parentRunId): string
    {
        $this->validatePathComponent($parentRunId, 'parentRunId');

        return $this->sessionsBasePath.'/'.$parentRunId.'/'.self::AGENTS_SUBDIR.'/registry.json';
    }

    /**
     * Absolute path for a parent-relative artifact file.
     *
     * Returns: <sessionsBase>/<parentRunId>/<relative>
     */
    public function absolutePath(string $parentRunId, string $relative): string
    {
        $this->validatePathComponent($parentRunId, 'parentRunId');

        return $this->sessionsBasePath.'/'.$parentRunId.'/'.$relative;
    }

    /**
     * Absolute state.json path for a child artifact.
     *
     * Uses {@see AgentArtifactPathsDTO} for the canonical relative path.
     */
    public function statePath(string $parentRunId, string $artifactId): string
    {
        $this->validatePathComponent($parentRunId, 'parentRunId');
        $this->validatePathComponent($artifactId, 'artifactId');

        $paths = AgentArtifactPathsDTO::forArtifactId($artifactId);

        return $this->sessionsBasePath.'/'.$parentRunId.'/'.$paths->statePath;
    }

    /**
     * Absolute events.jsonl path for a child artifact.
     *
     * Uses {@see AgentArtifactPathsDTO} for the canonical relative path.
     */
    public function eventsPath(string $parentRunId, string $artifactId): string
    {
        $this->validatePathComponent($parentRunId, 'parentRunId');
        $this->validatePathComponent($artifactId, 'artifactId');

        $paths = AgentArtifactPathsDTO::forArtifactId($artifactId);

        return $this->sessionsBasePath.'/'.$parentRunId.'/'.$paths->eventsPath;
    }

    /**
     * Reject path components that could escape the session directory.
     *
     * Embedded patterns like "foo..bar" are harmless because path separators
     * are already blocked.
     *
     * @throws \InvalidArgumentException
     */
    public function validatePathComponent(string $value, string $field): void
    {
        if ('' === $value) {
            throw new \InvalidArgumentException(\sprintf('"%s" must not be empty.', $field));
        }

        if (false !== strpbrk($value, '/\\')) {
            throw new \InvalidArgumentException(\sprintf('"%s" must not contain path separators: got "%s".', $field, $value));
        }

        if ('..' === $value || '.' === $value) {
            throw new \InvalidArgumentException(\sprintf('"%s" must not be "%s".', $field, $value));
        }

        if (str_contains($value, "\0")) {
            throw new \InvalidArgumentException(\sprintf('"%s" must not contain NUL bytes.', $field));
        }
    }
}
