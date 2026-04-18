<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Application\Reducer;

use Ineersa\AgentCore\Domain\Run\RunState;

final readonly class ReduceResult
{
    /**
     * @param list<object> $effects
     */
    public function __construct(
        public RunState $state,
        public array $effects,
    ) {
    }
}
