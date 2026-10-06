<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Contract\Tool;

use Ineersa\AgentCore\Domain\Coordination\VerifiedTransitionDTO;
use Ineersa\AgentCore\Domain\Message\ExecuteToolCall;
use Ineersa\AgentCore\Domain\Message\ToolCallResult;

interface ToolExecutionAuthorizationInterface
{
    /** @return list<\Ineersa\AgentCore\Domain\Message\ToolExecutionOutcomeUnknown> */
    public function unknownExecutionsForRepair(string $runId): array;

    public function retireUnknownExecution(\Ineersa\AgentCore\Domain\Coordination\RetireUnknownExecutionDTO $action, VerifiedTransitionDTO $transition): void;

    public function assertNoUnknownExecution(string $runId): void;

    public function unknownNoticePending(\Ineersa\AgentCore\Domain\Message\ToolExecutionOutcomeUnknown $notice): bool;

    public function matchesCurrentInvocation(\Ineersa\AgentCore\Domain\Message\ToolExecutionOutcomeUnknown $notice): bool;

    public function arm(ExecuteToolCall $call): void;

    public function claim(ExecuteToolCall $call): string|ToolCallResult|null;

    public function saveResult(ExecuteToolCall $call, string $claim, ToolCallResult $result): void;

    public function transferToDeferred(ExecuteToolCall $call, string $deferredId): void;

    public function isDisposed(ToolCallResult $result): bool;

    public function prepareDisposition(ToolCallResult $result, string $disposition): ?\Ineersa\AgentCore\Domain\Coordination\ToolResultDispositionDTO;

    public function validateDisposition(\Ineersa\AgentCore\Domain\Coordination\ToolResultDispositionDTO $descriptor, VerifiedTransitionDTO $transition): void;

    public function applyDisposition(\Ineersa\AgentCore\Domain\Coordination\ToolResultDispositionDTO $descriptor, VerifiedTransitionDTO $transition): void;

    /** @return string next snapshot filename cursor, or empty when exhausted for the run */
    public function reclaimDisposedPayloads(string $runId, string $afterFilename): string;
}
