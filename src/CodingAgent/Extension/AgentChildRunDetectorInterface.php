<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Extension;

/**
 * Detects whether a run is an agent child (fork/subagent) session.
 *
 * Implementations typically source this from RunStarted metadata
 * (`session.kind=agent_child`).
 *
 * @internal codingAgent-owned selection seam; not part of public ExtensionApi
 */
interface AgentChildRunDetectorInterface
{
    public function isAgentChild(string $runId): bool;
}
