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

    public function parentRunId(): string
    {
        return $this->identity->parentRunId;
    }

    public function childRunId(): string
    {
        return $this->identity->childRunId;
    }

    public function artifactId(): string
    {
        return $this->identity->artifactId;
    }

    public function displayName(): string
    {
        return $this->identity->displayName;
    }

    public function taskSummary(): string
    {
        return $this->identity->taskSummary;
    }

    public function definitionModel(): ?string
    {
        return $this->identity->definitionModel;
    }
}
