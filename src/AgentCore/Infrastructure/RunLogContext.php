<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Infrastructure;

/**
 * Thread-safe logging correlation context for long-lived workers.
 *
 * Uses a static stack so nested scopes do not corrupt outer context.
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
    /** @var list<array<string, mixed>> */
    private static array $stack = [];

    /**
     * Push new correlation context onto the stack.
     *
     * Merged on top of parent context. Must be paired with leave().
     *
     * @param array<string, mixed> $context fields: run_id, session_id, component, handler, queue, etc
     */
    public static function enter(array $context): void
    {
        $parent = self::$stack[array_key_last(self::$stack)] ?? [];
        self::$stack[] = [...$parent, ...$context];
    }

    /**
     * Pop the most recent context. No-op when stack is empty.
     */
    public static function leave(): void
    {
        if ([] !== self::$stack) {
            array_pop(self::$stack);
        }
    }

    /**
     * Current merged context, or empty array outside any scope.
     *
     * @return array<string, mixed>
     */
    public static function current(): array
    {
        return self::$stack[array_key_last(self::$stack)] ?? [];
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
     * Reset all context — for testing and worker startup.
     */
    public static function reset(): void
    {
        self::$stack = [];
    }
}
