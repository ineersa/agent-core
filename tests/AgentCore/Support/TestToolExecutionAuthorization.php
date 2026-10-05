<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Tests\Support;

use Ineersa\AgentCore\Contract\Tool\ToolExecutionAuthorizationInterface;
use Ineersa\AgentCore\Domain\Message\ExecuteToolCall;
use Ineersa\AgentCore\Domain\Message\ToolCallResult;

/** Worker unit fixtures model an admitted call; durable protocol proofs use the real store. */
final class TestToolExecutionAuthorization implements ToolExecutionAuthorizationInterface
{
    public function unknownExecutionsForRepair(string $runId): array
    {
        return [];
    }

    public function unknownNoticePending(\Ineersa\AgentCore\Domain\Message\ToolExecutionOutcomeUnknown $notice): bool
    {
        throw new \LogicException('Worker unit fixture has no unknown receipt.');
    }

    public function matchesCurrentInvocation(\Ineersa\AgentCore\Domain\Message\ToolExecutionOutcomeUnknown $notice): bool
    {
        throw new \LogicException('Worker unit fixture cannot match unknown invocation.');
    }

    public function retireUnknownExecution(\Ineersa\AgentCore\Domain\Coordination\RetireUnknownExecutionDTO $action, \Ineersa\AgentCore\Domain\Coordination\VerifiedTransitionDTO $transition): void
    {
        throw new \LogicException('Worker unit fixture cannot retire unknown execution.');
    }

    public function assertNoUnknownExecution(string $runId): void
    {
    }

    public function transferToDeferred(ExecuteToolCall $call, string $deferredId): void
    {
    }

    public function isDisposed(ToolCallResult $result): bool
    {
        return false;
    }

    public function prepareDisposition(ToolCallResult $result, string $disposition): ?\Ineersa\AgentCore\Domain\Coordination\ToolResultDispositionDTO
    {
        return null;
    }

    public function validateDisposition(\Ineersa\AgentCore\Domain\Coordination\ToolResultDispositionDTO $descriptor, \Ineersa\AgentCore\Domain\Coordination\VerifiedTransitionDTO $transition): void
    {
    }

    public function applyDisposition(\Ineersa\AgentCore\Domain\Coordination\ToolResultDispositionDTO $descriptor, \Ineersa\AgentCore\Domain\Coordination\VerifiedTransitionDTO $transition): void
    {
    }

    public function arm(ExecuteToolCall $call): void
    {
    }

    public function claim(ExecuteToolCall $call): string|ToolCallResult|null
    {
        return 'unit-fixture-claim';
    }

    public function saveResult(ExecuteToolCall $call, string $claim, ToolCallResult $result): void
    {
    }
}
