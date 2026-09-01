<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Execution\ChildRun\Contract;

use Ineersa\AgentCore\Domain\Run\StartRunInput;

final readonly class PreparedAgentChildRunDTO
{
    public function __construct(
        public ChildRunIdentityDTO $identity,
        public StartRunInput $startRunInput,
    ) {
    }
}
