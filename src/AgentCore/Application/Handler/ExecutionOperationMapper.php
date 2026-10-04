<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Application\Handler;

use Ineersa\AgentCore\Domain\Message\AbstractAgentBusMessage;
use Ineersa\AgentCore\Domain\Message\CompactionStepResult;
use Ineersa\AgentCore\Domain\Message\ExecuteCompactionStep;
use Ineersa\AgentCore\Domain\Message\ExecuteLlmStep;
use Ineersa\AgentCore\Domain\Message\ExecuteShellToolCall;
use Ineersa\AgentCore\Domain\Message\LlmStepResult;
use Ineersa\AgentCore\Domain\Message\ToolCallResult;

final class ExecutionOperationMapper
{
    public static function supports(object $request): bool
    {
        return $request instanceof ExecuteLlmStep || $request instanceof ExecuteCompactionStep || $request instanceof ExecuteShellToolCall;
    }

    /** @return class-string<AbstractAgentBusMessage> */
    public static function resultType(AbstractAgentBusMessage $request): string
    {
        return match (true) {
            $request instanceof ExecuteLlmStep => LlmStepResult::class,
            $request instanceof ExecuteCompactionStep => CompactionStepResult::class,
            $request instanceof ExecuteShellToolCall => ToolCallResult::class,
            default => throw new \InvalidArgumentException('Unsupported execution operation.'),
        };
    }
}
