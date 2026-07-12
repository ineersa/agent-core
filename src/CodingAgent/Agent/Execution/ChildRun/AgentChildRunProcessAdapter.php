<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Execution\ChildRun;

use Ineersa\AgentCore\Contract\AgentRunnerInterface;
use Ineersa\AgentCore\Contract\RunStoreInterface;
use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\AgentCore\Domain\Run\StartRunInput;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Port\ChildRunProcessPort;

final class AgentChildRunProcessAdapter implements ChildRunProcessPort
{
    public function __construct(
        private readonly AgentRunnerInterface $agentRunner,
        private readonly RunStoreInterface $childRunStore,
    ) {
    }

    public function start(StartRunInput $input): void
    {
        $this->agentRunner->start($input);
    }

    public function cancel(string $childRunId, string $reason): void
    {
        $this->agentRunner->cancel($childRunId, $reason);
    }

    public function getState(string $childRunId): ?RunState
    {
        return $this->childRunStore->get($childRunId);
    }
}
