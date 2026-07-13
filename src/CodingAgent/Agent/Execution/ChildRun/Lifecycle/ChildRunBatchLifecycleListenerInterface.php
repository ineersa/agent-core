<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Execution\ChildRun\Lifecycle;

use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Contract\ChildRunIdentityDTO;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Contract\ChildRunProgressUpdateDTO;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Contract\ChildRunTerminalOutcomeDTO;

/**
 * Child-kind hooks for progress emission and terminal artifact side effects during batch supervision.
 */
interface ChildRunBatchLifecycleListenerInterface
{
    public function progressSignature(ChildRunProgressUpdateDTO $update): string;

    public function emitProgress(ChildRunProgressUpdateDTO $update): void;

    public function mapTerminalProgressStatus(RunState $state): string;

    public function summarizeCompletedSummary(RunState $state): string;

    public function applyTerminalOutcome(ChildRunTerminalOutcomeDTO $outcome): void;

    public function completeToolResult(ChildRunIdentityDTO $identity, RunState $state): string;

    public function failToolResult(ChildRunIdentityDTO $identity, RunState $state): string;

    public function cancelChildToolResult(ChildRunIdentityDTO $identity, RunState $state): string;

    public function timeoutToolResult(ChildRunIdentityDTO $identity, int $timeoutSeconds): string;
}
