<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Application\Pipeline;

use Ineersa\AgentCore\Domain\Run\RunState;

/**
 * @internal
 *
 * Structured result from the unified applyPendingCommands method.
 * Carries the mutated state, event specs, boundary-specific
 * shouldContinue flag for the stop-boundary path, and outbound
 * effects (e.g. CompactRun messages from drained compact commands).
 */
readonly class CommandApplicationResult
{
    /**
     * @param list<array{type: string, payload: array<string, mixed>}> $eventSpecs
     * @param list<object>                                             $effects    Outbound messages to dispatch post-commit (e.g. CompactRun)
     */
    public function __construct(
        public RunState $state,
        public array $eventSpecs,
        public bool $shouldContinue = false,
        public array $effects = [],
    ) {
    }
}
