<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Contract\Tool;

use Ineersa\AgentCore\Domain\Message\ExecuteToolCall;
use Ineersa\AgentCore\Domain\Message\ToolCallResult;

interface ToolExecutionAuthorizationInterface
{
    public function assertNoUnknownExecution(string $runId): void;

    public function arm(ExecuteToolCall $call): void;

    public function claim(ExecuteToolCall $call): string|ToolCallResult|null;

    public function saveResult(ExecuteToolCall $call, string $claim, ToolCallResult $result): void;

    public function transferToDeferred(ExecuteToolCall $call, string $deferredId): void;

    public function isDisposed(ToolCallResult $result): bool;

    public function prepareDisposition(ToolCallResult $result, string $disposition): ?\Ineersa\AgentCore\Domain\Coordination\ToolResultDispositionDTO;

    public function validateDisposition(\Ineersa\AgentCore\Domain\Coordination\ToolResultDispositionDTO $descriptor, \Ineersa\AgentCore\Domain\Coordination\VerifiedTransitionDTO $transition): void;

    public function applyDisposition(\Ineersa\AgentCore\Domain\Coordination\ToolResultDispositionDTO $descriptor, \Ineersa\AgentCore\Domain\Coordination\VerifiedTransitionDTO $transition): void;
}
