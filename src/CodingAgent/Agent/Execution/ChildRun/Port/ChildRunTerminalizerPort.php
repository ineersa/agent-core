<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Execution\ChildRun\Port;

use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\ChildRunIdentityDTO;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\ChildRunTerminalOutcomeDTO;

interface ChildRunTerminalizerPort
{
    public function applyTerminalOutcome(ChildRunTerminalOutcomeDTO $outcome): void;

    public function summarizeCompletedSummary(RunState $state): string;

    public function completeToolResult(ChildRunIdentityDTO $identity, RunState $state): string;

    public function failToolResult(ChildRunIdentityDTO $identity, RunState $state): string;

    public function cancelChildToolResult(ChildRunIdentityDTO $identity, RunState $state): string;

    public function parentCancelledSingleMessage(ChildRunIdentityDTO $identity): string;

    public function timeoutToolResult(ChildRunIdentityDTO $identity, int $timeoutSeconds): string;
}
