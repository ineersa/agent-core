<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Session;

use Ineersa\CodingAgent\Runtime\Contract\ChildAgentEventsPathResolverInterface;

/**
 * Resolves canonical child artifact events.jsonl under the parent session directory.
 *
 * Relative layout matches {@see \Ineersa\CodingAgent\Agent\Artifact\AgentArtifactPathsDTO}
 * without depending on the AppAgent artifact layer (Deptrac: AppSession must not use AppAgent).
 */
final readonly class ChildAgentEventsPathResolver implements ChildAgentEventsPathResolverInterface
{
    private const string AGENTS_SUBDIR = 'artifacts/agents';

    public function __construct(
        private HatfieldSessionStore $hatfieldSessionStore,
    ) {
    }

    public function eventsPath(string $parentSessionId, string $artifactId): string
    {
        $this->validatePathComponent($parentSessionId, 'parentSessionId');
        $this->validatePathComponent($artifactId, 'artifactId');

        $relative = self::AGENTS_SUBDIR.'/'.$artifactId.'/events.jsonl';

        return $this->hatfieldSessionStore->resolveSessionsBasePath().'/'.$parentSessionId.'/'.$relative;
    }

    /**
     * @throws \InvalidArgumentException
     */
    private function validatePathComponent(string $value, string $field): void
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
