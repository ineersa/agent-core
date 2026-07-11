<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\Contract;

/**
 * Resolves canonical child agent events.jsonl paths under a parent session directory.
 */
interface ChildAgentEventsPathResolverInterface
{
    /**
     * Absolute path to .hatfield/sessions/<parentSessionId>/artifacts/agents/<artifactId>/events.jsonl.
     *
     * @throws \InvalidArgumentException when identifiers are unsafe
     */
    public function eventsPath(string $parentSessionId, string $artifactId): string;
}
