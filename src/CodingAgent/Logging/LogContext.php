<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Logging;

use Ineersa\AgentCore\Infrastructure\RunLogContext;

/**
 * CodingAgent-side alias for {@see RunLogContext}.
 *
 * Exists so that CodingAgent code has a natural import path. Delegates
 * directly to the AgentCore static context stack.
 *
 * @see RunLogContext
 */
final class LogContext
{
    /**
     * Push new correlation context onto the stack.
     *
     * @param array<string, mixed> $context
     */
    public static function enter(array $context): void
    {
        RunLogContext::enter($context);
    }

    /**
     * Pop the most recent context.
     */
    public static function leave(): void
    {
        RunLogContext::leave();
    }

    /**
     * Current merged context.
     *
     * @return array<string, mixed>
     */
    public static function current(): array
    {
        return RunLogContext::current();
    }

    /**
     * Enter a scope for the given callable.
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
        return RunLogContext::scoped($context, $operation);
    }

    /**
     * Reset all context — for testing.
     */
    public static function reset(): void
    {
        RunLogContext::reset();
    }
}
