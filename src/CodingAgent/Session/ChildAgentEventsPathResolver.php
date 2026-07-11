<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Session;

use Ineersa\CodingAgent\Runtime\Contract\ChildAgentEventsPathResolverInterface;

/**
 * Runtime/TUI adapter for canonical child artifact events.jsonl paths.
 *
 * Delegates to {@see SessionAgentArtifactPathResolver} so picker code never
 * depends on AppAgent artifact internals.
 */
final readonly class ChildAgentEventsPathResolver implements ChildAgentEventsPathResolverInterface
{
    public function __construct(
        private SessionAgentArtifactPathResolver $canonical,
    ) {
    }

    public function eventsPath(string $parentSessionId, string $artifactId): string
    {
        return $this->canonical->eventsPath($parentSessionId, $artifactId);
    }
}
