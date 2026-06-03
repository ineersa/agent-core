<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Application\Pipeline;

use Ineersa\AgentCore\Domain\Run\RunState;

/**
 * @internal
 *
 * Structured result from the unified applyPendingCommands method.
 * Carries the mutated state, event specs, and boundary-specific
 * shouldContinue flag for the stop-boundary path.
 */
readonly class CommandApplicationResult
{
    /**
     * @param list<array{type: string, payload: array<string, mixed>}> $eventSpecs
     */
    public function __construct(
        public RunState $state,
        public array $eventSpecs,
        public bool $shouldContinue = false,
    ) {
    }
}
