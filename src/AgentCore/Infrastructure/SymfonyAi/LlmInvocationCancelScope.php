<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Infrastructure\SymfonyAi;

use Ineersa\AgentCore\Contract\Hook\CancellationTokenInterface;

/**
 * Per-fiber scope for the cancel token of the in-flight LLM HTTP invocation.
 *
 * Distinct from {@see \Ineersa\AgentCore\Infrastructure\RunLogContext}: this is
 * not logging correlation and must never be merged into log records.
 */
final class LlmInvocationCancelScope
{
    /**
     * @var \WeakMap<\Fiber<mixed, mixed, mixed, mixed>, list<CancellationTokenInterface>>|null
     */
    private static ?\WeakMap $fiberStacks = null;

    /** @var list<CancellationTokenInterface> */
    private static array $defaultStack = [];

    public static function enter(CancellationTokenInterface $token): void
    {
        $stack = self::readStack();
        $stack[] = $token;
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

        return $stack[array_key_last($stack)];
    }

    /**
     * @return list<CancellationTokenInterface>
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
     * @param list<CancellationTokenInterface> $stack
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
