<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Application\Handler;

use Ineersa\AgentCore\Domain\Coordination\ExecutionAuthorizationStamp;
use Ineersa\AgentCore\Domain\Message\AbstractAgentBusMessage;
use Ineersa\AgentCore\Domain\Message\DurableExecutionResult;

/** Synchronous Messenger scopes only. No retained requests after worker handling. */
final class ExecutionBoundaryContext
{
    /** @var list<array{AbstractAgentBusMessage, ExecutionAuthorizationStamp, string}> */
    private array $executions = [];
    /** @var list<DurableExecutionResult> */
    private array $results = [];

    public function executing(AbstractAgentBusMessage $request, ExecutionAuthorizationStamp $authorization, string $claim, callable $handler): mixed
    {
        $this->executions[] = [$request, $authorization, $claim];
        try {
            return $handler();
        } finally {
            array_pop($this->executions);
        }
    }

    /** @return array{AbstractAgentBusMessage, ExecutionAuthorizationStamp, string}|null */
    public function currentExecution(): ?array
    {
        $key = array_key_last($this->executions);

        return null === $key ? null : $this->executions[$key];
    }

    public function accepting(DurableExecutionResult $result, callable $handler): mixed
    {
        $this->results[] = $result;
        try {
            return $handler();
        } finally {
            array_pop($this->results);
        }
    }

    public function currentResult(): ?DurableExecutionResult
    {
        $key = array_key_last($this->results);

        return null === $key ? null : $this->results[$key];
    }
}
