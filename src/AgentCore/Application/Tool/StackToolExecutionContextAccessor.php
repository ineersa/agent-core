<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Application\Tool;

/**
 * Stack-safe ambient context accessor used by ToolExecutor and app-owned tools.
 *
 * Each Fiber owns its nested scopes. Synchronous callers use a separate stack,
 * so a suspended tool cannot expose its context to another invocation.
 */
final class StackToolExecutionContextAccessor
{
    /** @var list<ToolContext> */
    private array $stack = [];

    /** @var \WeakMap<\Fiber<mixed, mixed, mixed, mixed>, list<ToolContext>> */
    private readonly \WeakMap $fiberStacks;

    public function __construct()
    {
        $this->fiberStacks = new \WeakMap();
    }

    public function depth(): int
    {
        return \count($this->currentStack());
    }

    public function current(): ?ToolContext
    {
        $stack = $this->currentStack();
        $key = array_key_last($stack);

        return null !== $key ? $stack[$key] : null;
    }

    public function requireCurrent(): ToolContext
    {
        return $this->current()
            ?? throw new \LogicException('A tool execution context is required but none is active.');
    }

    public function with(ToolContext $context, callable $callback): mixed
    {
        $fiber = \Fiber::getCurrent();
        $stack = $this->currentStack();
        if (null === $fiber) {
            $this->stack = [...$stack, $context];
        } else {
            $this->fiberStacks->offsetSet($fiber, [...$stack, $context]);
        }

        try {
            return $callback();
        } finally {
            if (null === $fiber) {
                $this->stack = $stack;
            } elseif ([] === $stack) {
                unset($this->fiberStacks[$fiber]);
            } else {
                $this->fiberStacks->offsetSet($fiber, $stack);
            }
        }
    }

    /** @return list<ToolContext> */
    private function currentStack(): array
    {
        $fiber = \Fiber::getCurrent();

        return null === $fiber ? $this->stack : ($this->fiberStacks[$fiber] ?? []);
    }
}
