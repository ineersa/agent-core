<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Infrastructure\SymfonyAi;

use Ineersa\AgentCore\Contract\Hook\CancellationTokenInterface;
use Ineersa\AgentCore\Infrastructure\RunLogContext;
use Revolt\EventLoop\FiberLocal;

/**
 * Per-fiber scope for the cancel token and run ID of an in-flight LLM invocation.
 *
 * Distinct from {@see RunLogContext}: this is
 * not logging correlation and must never be merged into log records.
 *
 * The stack lives in a Revolt {@see FiberLocal}. Revolt clears it on its
 * reusable callback Fibers after every callback, so enter() and leave()
 * must pair within one callback. Outside any Fiber, FiberLocal's main-fiber
 * stack is process-global and never cleared automatically. Keep at most one
 * outside-fiber LLM invocation active per process; concurrent workers must
 * run inside distinct Fibers so their stacks stay isolated.
 */
final class LlmInvocationCancelScope
{
    /** @var FiberLocal<list<LlmInvocationScopeEntry>>|null */
    private static ?FiberLocal $stack = null;

    public static function enter(CancellationTokenInterface $token, ?string $runId = null): void
    {
        $stack = self::stack()->get();
        $stack[] = new LlmInvocationScopeEntry($token, $runId);
        self::stack()->set($stack);
    }

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

    public static function current(): ?CancellationTokenInterface
    {
        return self::currentEntry()?->token;
    }

    public static function currentRunId(): ?string
    {
        return self::currentEntry()?->runId;
    }

    private static function currentEntry(): ?LlmInvocationScopeEntry
    {
        $stack = self::stack()->get();

        return [] === $stack ? null : $stack[array_key_last($stack)];
    }

    /** @return FiberLocal<list<LlmInvocationScopeEntry>> */
    private static function stack(): FiberLocal
    {
        return self::$stack ??= new FiberLocal(self::emptyStack(...));
    }

    /** @return list<LlmInvocationScopeEntry> */
    private static function emptyStack(): array
    {
        return [];
    }
}
