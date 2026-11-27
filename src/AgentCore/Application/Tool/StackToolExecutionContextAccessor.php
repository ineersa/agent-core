<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Application\Tool;

use Revolt\EventLoop\FiberLocal;

/**
 * Stack-safe ambient context accessor used by ToolExecutor and app-owned tools.
 *
 * Each Fiber owns its nested scopes through a per-instance Revolt
 * {@see FiberLocal}, so a suspended tool cannot expose its context to another
 * invocation. Synchronous callers use FiberLocal's main-fiber stack. Revolt
 * clears the stack on its reusable callback Fibers after every callback;
 * {@see with()} always restores its captured stack before returning, so no
 * scope outlives its callback.
 */
final class StackToolExecutionContextAccessor
{
    /** @var FiberLocal<list<ToolContext>> */
    private readonly FiberLocal $stack;

    public function __construct()
    {
        $this->stack = new FiberLocal(self::emptyStack(...));
    }

    public function depth(): int
    {
        return \count($this->stack->get());
    }

    public function current(): ?ToolContext
    {
        $stack = $this->stack->get();
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
        $stack = $this->stack->get();
        $this->stack->set([...$stack, $context]);

        try {
            return $callback();
        } finally {
            // Restore the captured snapshot rather than popping, so an
            // unbalanced nested scope cannot shift this caller's stack.
            if ([] === $stack) {
                $this->stack->unset();
            } else {
                $this->stack->set($stack);
            }
        }
    }

    /** @return list<ToolContext> */
    private static function emptyStack(): array
    {
        return [];
    }
}
