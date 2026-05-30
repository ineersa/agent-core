<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Infrastructure;

/**
 * Per-fiber logging correlation context for long-lived workers.
 *
 * Each PHP {@see \Fiber} maintains its own context stack so concurrent
 * fibers do not share or corrupt each other's correlation fields.
 * Code running outside any fiber (e.g. the main event loop) uses a
 * separate default stack.
 *
 * Each scope is entered via {@see enter()} and must leave via
 * {@see leave()} in a try/finally block.
 *
 * This lives in AgentCore so core pipeline code can scope context
 * without depending on CodingAgent. The Monolog processor
 * in CodingAgent reads the context via {@see current()} and merges
 * the fields into log records.
 *
 * @see \Ineersa\CodingAgent\Logging\LogContextProcessor
 */
final class RunLogContext
{
    /**
     * Per-fiber context stacks. Fibers are GC'd automatically when
     * they finish, and WeakMap entries are released with them.
     *
     * @var \WeakMap<\Fiber<mixed, mixed, mixed, mixed>, list<array<string, mixed>>>
     */
    private static ?\WeakMap $fiberStacks = null;

    /** @var list<array<string, mixed>> */
    private static array $defaultStack = [];

    /**
     * Push new correlation context onto the current fiber's stack.
     *
     * Merged on top of parent context. Must be paired with leave().
     *
     * @param array<string, mixed> $context fields: run_id, session_id, component, handler, queue, etc
     */
    public static function enter(array $context): void
    {
        $stack = self::readStack();
        $parent = [] !== $stack ? $stack[array_key_last($stack)] : [];
        $stack[] = [...$parent, ...$context];
        self::writeStack($stack);
    }

    /**
     * Pop the most recent context from the current fiber's stack.
     * No-op when the stack is empty.
     */
    public static function leave(): void
    {
        $stack = self::readStack();
        if ([] !== $stack) {
            array_pop($stack);
            self::writeStack($stack);
        }
    }

    /**
     * Current merged context, or empty array outside any scope.
     *
     * @return array<string, mixed>
     */
    public static function current(): array
    {
        $stack = self::readStack();

        if ([] === $stack) {
            return [];
        }

        return $stack[array_key_last($stack)];
    }

    /**
     * Enter a scope for the given callable and return its result.
     *
     * @template TResult
     *
     * @param array<string, mixed> $context
     * @param callable(): TResult  $operation
     *
     * @return TResult
     */
    public static function scoped(array $context, callable $operation): mixed
    {
        self::enter($context);

        try {
            return $operation();
        } finally {
            self::leave();
        }
    }

    /**
     * Reset all context for the current fiber (or default stack outside any fiber).
     *
     * For testing and worker startup. Does NOT affect other fibers' context — each
     * fiber owns its own stack via WeakMap; reset() only targets the current execution
     * context. WeakMap entries are automatically released when a fiber finishes
     * and gets GC'd, so explicit reset() is typically only needed between test cases
     * or worker iterations within the same fiber lifetime.
     */
    public static function reset(): void
    {
        $fiber = \Fiber::getCurrent();
        if (null === $fiber) {
            self::$defaultStack = [];
        } elseif (null !== self::$fiberStacks && self::$fiberStacks->offsetExists($fiber)) {
            self::$fiberStacks->offsetUnset($fiber);
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function readStack(): array
    {
        $fiber = \Fiber::getCurrent();

        if (null === $fiber) {
            return self::$defaultStack;
        }

        if (null === self::$fiberStacks || !self::$fiberStacks->offsetExists($fiber)) {
            return [];
        }

        return self::$fiberStacks[$fiber];
    }

    /**
     * @param list<array<string, mixed>> $stack
     */
    private static function writeStack(array $stack): void
    {
        $fiber = \Fiber::getCurrent();

        if (null === $fiber) {
            self::$defaultStack = $stack;

            return;
        }

        self::$fiberStacks ??= new \WeakMap();
        self::$fiberStacks->offsetSet($fiber, $stack);
    }
}
