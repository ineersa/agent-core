<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Application\Reducer;

use Ineersa\AgentCore\Domain\Run\RunState;

/**
 * ReduceResult is a readonly value object that encapsulates the outcome of a reducer operation, bundling the resulting RunState and a list of side-effect instructions. It serves as a pure data container to separate computation results from their execution, ensuring immutability and clear intent in the application's event processing flow.
 */
final readonly class ReduceResult
{
    /**
     * Initializes the result with the computed state and associated effects.
     *
     * @param list<object> $effects
     */
    public function __construct(
        public RunState $state,
        public array $effects,
    ) {
    }
}
