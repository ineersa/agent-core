<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Execution;

use Ineersa\CodingAgent\Agent\Fork\ForkChildLaunchInputBuilder;

final class ForkDeferredChildPreparationStrategyFactory
{
    public function __construct(
        private readonly ForkToolPolicyResolver $forkToolPolicyResolver,
        private readonly ForkChildLaunchInputBuilder $launchInputBuilder,
    ) {
    }

    public function create(ForkLaunchTaskDTO $launchTask): ForkDeferredChildPreparationStrategy
    {
        return new ForkDeferredChildPreparationStrategy(
            $this->forkToolPolicyResolver,
            $this->launchInputBuilder,
            $launchTask,
        );
    }
}
