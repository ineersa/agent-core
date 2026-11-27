<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Infrastructure;

use Ineersa\CodingAgent\Logging\LogContextProcessor;
use Revolt\EventLoop\FiberLocal;

/**
 * Per-fiber logging correlation context for long-lived workers.
 *
 * Each PHP {@see \Fiber} maintains its own context stack in a Revolt
 * {@see FiberLocal}, so concurrent fibers do not share or corrupt each
 * other's correlation fields. Code running outside any fiber (e.g. the
 * main event loop caller) uses FiberLocal's main-fiber storage, which
 * is process-global and never cleared automatically.
 *
 * Each scope is entered via {@see enter()} and must leave via
 * {@see leave()} in a try/finally block within the same event-loop
 * callback: Revolt clears FiberLocal storage on its reusable callback
 * Fibers after every callback, so a scope left open when its callback
 * returns is dropped instead of leaking into the next callback.
 *
 * This lives in AgentCore so core pipeline code can scope context
 * without depending on CodingAgent. The Monolog processor
 * in CodingAgent reads the context via {@see current()} and merges
 * the fields into log records.
 *
 * @see LogContextProcessor
 */
final class RunLogContext
{
    /** @var FiberLocal<list<array<string, mixed>>>|null */
    private static ?FiberLocal $stack = null;

    /**
     * Push new correlation context onto the current fiber's stack.
     *
     * Merged on top of parent context. Must be paired with leave().
     *
     * @param array<string, mixed> $context fields: run_id, session_id, component, handler, queue, etc
     */
    public static function enter(array $context): void
    {
        $stack = self::stack()->get();
        $parent = [] !== $stack ? $stack[array_key_last($stack)] : [];
        $stack[] = [...$parent, ...$context];
        self::stack()->set($stack);
    }

    /**
     * Pop the most recent context from the current fiber's stack.
     * No-op when the stack is empty.
     */
    public static function leave(): void
    {
        $stack = self::stack()->get();
        array_pop($stack);
        if ([] === $stack) {
            self::stack()->unset();

            return;
        }

        self::stack()->set($stack);
    }

    /**
     * Current merged context, or empty array outside any scope.
     *
     * @return array<string, mixed>
     */
    public static function current(): array
    {
        $stack = self::stack()->get();

        return [] === $stack ? [] : $stack[array_key_last($stack)];
    }

    /** @return FiberLocal<list<array<string, mixed>>> */
    private static function stack(): FiberLocal
    {
        return self::$stack ??= new FiberLocal(self::emptyStack(...));
    }

    /** @return list<array<string, mixed>> */
    private static function emptyStack(): array
    {
        return [];
    }
}
