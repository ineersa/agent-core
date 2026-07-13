<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Execution\ChildRun;

use Ineersa\AgentCore\Domain\Run\RunState;

/**
 * Required single-child progress context; callers must supply identity and run state explicitly.
 */
final readonly class ChildRunSingleProgressContextDTO
{
    public function __construct(
        public ChildRunIdentityDTO $identity,
        public RunState $state,
        public string $progressStatus,
    ) {
    }
}
