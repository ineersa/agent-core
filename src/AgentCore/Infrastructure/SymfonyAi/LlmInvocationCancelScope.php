<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Infrastructure\SymfonyAi;

use Ineersa\AgentCore\Contract\Hook\CancellationTokenInterface;

/**
 * Per-fiber scope for the cancel token and run ID of an in-flight LLM invocation.
 *
 * Distinct from {@see \Ineersa\AgentCore\Infrastructure\RunLogContext}: this is
 * not logging correlation and must never be merged into log records.
 *
 * Outside any Fiber, the default stack is process-global. Keep at most one
 * outside-fiber LLM invocation active per process; concurrent workers must
 * run inside distinct Fibers so their stacks stay isolated.
 */
final class LlmInvocationCancelScope
{
    /**
     * @var \WeakMap<\Fiber<mixed, mixed, mixed, mixed>, list<LlmInvocationScopeEntry>>|null
     */
    private static ?\WeakMap $fiberStacks = null;

    /** @var list<LlmInvocationScopeEntry> */
    private static array $defaultStack = [];

    public static function enter(CancellationTokenInterface $token, ?string $runId = null): void
    {
        $stack = self::readStack();
        $stack[] = new LlmInvocationScopeEntry($token, $runId);
        self::writeStack($stack);
    }

    public static function leave(): void
    {
        $stack = self::readStack();
        if ([] !== $stack) {
            array_pop($stack);
            self::writeStack($stack);
        }
    }

    public static function current(): ?CancellationTokenInterface
    {
        $stack = self::readStack();
        if ([] === $stack) {
            return null;
        }

        return $stack[array_key_last($stack)]->token;
    }

    public static function currentRunId(): ?string
    {
        $stack = self::readStack();

        return [] === $stack ? null : $stack[array_key_last($stack)]->runId;
    }

    /**
     * @return list<LlmInvocationScopeEntry>
     */
    private static function readStack(): array
    {
        $fiber = \Fiber::getCurrent();
        if (null === $fiber) {
            return self::$defaultStack;
        }

        self::$fiberStacks ??= new \WeakMap();

        return self::$fiberStacks[$fiber] ?? [];
    }

    /**
     * @param list<LlmInvocationScopeEntry> $stack
     */
    private static function writeStack(array $stack): void
    {
        $fiber = \Fiber::getCurrent();
        if (null === $fiber) {
            self::$defaultStack = $stack;

            return;
        }

        self::$fiberStacks ??= new \WeakMap();
        if ([] === $stack) {
            self::$fiberStacks->offsetUnset($fiber);

            return;
        }

        self::$fiberStacks->offsetSet($fiber, $stack);
    }
}
