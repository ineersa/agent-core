<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Session;

/**
 * Canonical filesystem path resolver for parent-scoped agent artifacts under session storage.
 *
 * Relative layout matches agent artifact storage ({@code artifacts/agents/<artifactId>/…}).
 * AppAgent {@see \Ineersa\CodingAgent\Agent\Artifact\AgentArtifactPathResolver} and runtime
 * {@see ChildAgentEventsPathResolver} delegate here so TUI/runtime never depend on AppAgent.
 *
 * Does NOT create directories — callers mkdir when writing files.
 */
final class SessionAgentArtifactPathResolver
{
    /** Directory permission for artifact storage directories. */
    public const int DIR_PERMISSIONS = 0755;

    /** File permission for artifact storage files. */
    public const int FILE_PERMISSIONS = 0644;

    /** Relative to parent session directory. */
    private const string AGENTS_SUBDIR = 'artifacts/agents';

    private readonly string $sessionsBasePath;

    public function __construct(
        private readonly HatfieldSessionStore $hatfieldSessionStore,
    ) {
        $this->sessionsBasePath = $hatfieldSessionStore->resolveSessionsBasePath();
    }

    public function resolveArtifactsBasePath(string $parentRunId): string
    {
        $this->validatePathComponent($parentRunId, 'parentRunId');

        return $this->sessionsBasePath.'/'.$parentRunId.'/'.self::AGENTS_SUBDIR;
    }

    public function resolveArtifactDir(string $parentRunId, string $artifactId): string
    {
        $this->validatePathComponent($parentRunId, 'parentRunId');
        $this->validatePathComponent($artifactId, 'artifactId');

        return $this->resolveArtifactsBasePath($parentRunId).'/'.$artifactId;
    }

    public function registryPath(string $parentRunId): string
    {
        $this->validatePathComponent($parentRunId, 'parentRunId');

        return $this->sessionsBasePath.'/'.$parentRunId.'/'.self::AGENTS_SUBDIR.'/registry.json';
    }

    public function absolutePath(string $parentRunId, string $relative): string
    {
        $this->validatePathComponent($parentRunId, 'parentRunId');

        return $this->sessionsBasePath.'/'.$parentRunId.'/'.$relative;
    }

    public function statePath(string $parentRunId, string $artifactId): string
    {
        $this->validatePathComponent($parentRunId, 'parentRunId');
        $this->validatePathComponent($artifactId, 'artifactId');

        $relative = $this->relativeArtifactDir($artifactId).'/state.json';

        return $this->sessionsBasePath.'/'.$parentRunId.'/'.$relative;
    }

    public function eventsPath(string $parentRunId, string $artifactId): string
    {
        $this->validatePathComponent($parentRunId, 'parentRunId');
        $this->validatePathComponent($artifactId, 'artifactId');

        $relative = $this->relativeArtifactDir($artifactId).'/events.jsonl';

        return $this->sessionsBasePath.'/'.$parentRunId.'/'.$relative;
    }

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

    private function relativeArtifactDir(string $artifactId): string
    {
        return self::AGENTS_SUBDIR.'/'.$artifactId;
    }
}
