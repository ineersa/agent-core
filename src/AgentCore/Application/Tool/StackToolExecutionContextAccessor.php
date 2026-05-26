<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Application\Tool;

use Ineersa\AgentCore\Contract\Tool\ToolExecutionContextInterface;

/**
 * Stack-safe ambient context accessor used by ToolExecutor and app-owned tools.
 *
 * The stack is synchronous and compatible with the current CLI/Messenger
 * execution model. If in-process parallel/fiber execution is introduced,
 * replace with an operation-id or fiber-local strategy.
 */
final class StackToolExecutionContextAccessor
{
    /** @var list<ToolExecutionContextInterface> */
    private array $stack = [];

    public function current(): ?ToolExecutionContextInterface
    {
        $key = array_key_last($this->stack);

        return null !== $key ? $this->stack[$key] : null;
    }

    public function requireCurrent(): ToolExecutionContextInterface
    {
        return $this->current()
            ?? throw new \LogicException('A tool execution context is required but none is active.');
    }

    public function with(ToolExecutionContextInterface $context, callable $callback): mixed
    {
        $this->stack[] = $context;

        try {
            return $callback();
        } finally {
            array_pop($this->stack);
        }
    }
}
