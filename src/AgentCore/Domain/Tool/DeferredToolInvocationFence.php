<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Domain\Tool;

use Ineersa\AgentCore\Domain\Message\ExecuteToolCall;
use Ineersa\AgentCore\Domain\Message\ToolCallResult;
use Symfony\Component\Serializer\SerializerInterface;

/** Full immutable invocation comparison for deferred registration and settlement. */
final readonly class DeferredToolInvocationFence
{
    public function __construct(private SerializerInterface $serializer)
    {
    }

    public function sameInvocation(ExecuteToolCall $call, ?ExecuteToolCall $other): bool
    {
        return null !== $other && $this->serializer->serialize($call, 'json') === $this->serializer->serialize($other, 'json');
    }

    public function matchesResult(ToolCallResult $result, ExecuteToolCall $call): bool
    {
        return [$result->runId(), $result->turnNo(), $result->stepId(), $result->attempt(), $result->idempotencyKey(), $result->toolCallId, $result->orderIndex, $result->result['tool_name'] ?? null]
            === [$call->runId(), $call->turnNo(), $call->stepId(), $call->attempt(), $call->idempotencyKey(), $call->toolCallId, $call->orderIndex, $call->toolName];
    }

    public function matchesCorrelation(DeferredToolCompletionCorrelation $correlation, ExecuteToolCall $call): bool
    {
        return [$correlation->runId, $correlation->turnNo, $correlation->stepId, $correlation->attempt, $correlation->idempotencyKey, $correlation->toolCallId, $correlation->toolName, $correlation->arguments, $correlation->orderIndex, $correlation->toolIdempotencyKey, $correlation->mode, $correlation->timeoutSeconds, $correlation->maxParallelism, $correlation->argSchema, $correlation->toolsRef]
            === [$call->runId(), $call->turnNo(), $call->stepId(), $call->attempt(), $call->idempotencyKey(), $call->toolCallId, $call->toolName, $call->args, $call->orderIndex, $call->toolIdempotencyKey, $call->mode, $call->timeoutSeconds, $call->maxParallelism, $call->argSchema, $call->toolsRef];
    }
}
