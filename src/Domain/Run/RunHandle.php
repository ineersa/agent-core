<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Domain\Run;

/**
 * A value object representing a unique identifier for a run within the agent core domain. It encapsulates the run ID as a strongly-typed string to enforce type safety and semantic clarity across the application.
 */
final readonly class RunHandle
{
    /**
     * Initializes the run handle with a specific run ID string.
     */
    public function __construct(public string $runId)
    {
    }
}
