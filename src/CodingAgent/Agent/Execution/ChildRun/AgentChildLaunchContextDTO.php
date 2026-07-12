<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Execution\ChildRun;

/**
 * Inherited prompt context resolved for a foreground child launch.
 */
final readonly class AgentChildLaunchContextDTO
{
    public function __construct(
        public string $agentsMd,
        public string $skillsContext,
        public string $agentsDefinitionsContext,
    ) {
    }
}
