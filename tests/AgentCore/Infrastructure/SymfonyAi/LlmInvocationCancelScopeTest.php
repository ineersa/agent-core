<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Tests\Infrastructure\SymfonyAi;

use Amp\DeferredFuture;
use Ineersa\AgentCore\Contract\Hook\NullCancellationToken;
use Ineersa\AgentCore\Infrastructure\SymfonyAi\LlmInvocationCancelScope;
use Ineersa\AgentCore\Tests\Support\IsolatedEventLoop;
use PHPUnit\Framework\TestCase;
use Revolt\EventLoop;

use function Amp\async;

final class LlmInvocationCancelScopeTest extends TestCase
{
    use IsolatedEventLoop;

    protected function setUp(): void
    {
        $this->clearDefaultScope();
    }

    protected function tearDown(): void
    {
        $this->clearDefaultScope();
    }

    public function testEmptyOutsideAnyScope(): void
    {
        $this->assertNull(LlmInvocationCancelScope::current());
        $this->assertNull(LlmInvocationCancelScope::currentRunId());
    }

    public function testNestedScopesExposeInnermostEntryAndUnwind(): void
    {
        $outer = new NullCancellationToken();
        $inner = new NullCancellationToken();

        LlmInvocationCancelScope::enter($outer, 'run-outer');
        LlmInvocationCancelScope::enter($inner);
        $this->assertSame($inner, LlmInvocationCancelScope::current());
        $this->assertNull(LlmInvocationCancelScope::currentRunId(), 'The inner scope run ID must not fall back to the outer one.');

        LlmInvocationCancelScope::leave();
        $this->assertSame($outer, LlmInvocationCancelScope::current());
        $this->assertSame('run-outer', LlmInvocationCancelScope::currentRunId());

        LlmInvocationCancelScope::leave();
        LlmInvocationCancelScope::leave(); // extra leave is a no-op
        $this->assertNull(LlmInvocationCancelScope::current());
        $this->assertNull(LlmInvocationCancelScope::currentRunId());
    }

    public function testFibersAreIsolatedFromMainAndEachOtherAcrossSuspend(): void
    {
        $mainToken = new NullCancellationToken();
        LlmInvocationCancelScope::enter($mainToken, 'run-main');

        $fiber = static fn (string $runId): \Fiber => new \Fiber(static function () use ($runId): void {
            self::assertNull(LlmInvocationCancelScope::current(), 'A Fiber must not inherit the main scope.');
            $token = new NullCancellationToken();
            LlmInvocationCancelScope::enter($token, $runId);
            \Fiber::suspend();
            self::assertSame($token, LlmInvocationCancelScope::current());
            self::assertSame($runId, LlmInvocationCancelScope::currentRunId());
            LlmInvocationCancelScope::leave();
            self::assertNull(LlmInvocationCancelScope::current());
        });
        $a = $fiber('run-a');
        $b = $fiber('run-b');

        $a->start();
        $b->start();
        $this->assertSame('run-main', LlmInvocationCancelScope::currentRunId());
        $a->resume();
        $b->resume();

        $this->assertTrue($a->isTerminated());
        $this->assertTrue($b->isTerminated());
        $this->assertSame($mainToken, LlmInvocationCancelScope::current());
        $this->assertSame('run-main', LlmInvocationCancelScope::currentRunId());
    }

    public function testScopeSurvivesAwaitInsideAsyncWhileSiblingRuns(): void
    {
        $gate = new DeferredFuture();
        $owner = async(static function () use ($gate): ?string {
            LlmInvocationCancelScope::enter(new NullCancellationToken(), 'run-owner');
            try {
                $gate->getFuture()->await();

                return LlmInvocationCancelScope::currentRunId();
            } finally {
                LlmInvocationCancelScope::leave();
            }
        });
        $sibling = async(static function () use ($gate): ?string {
            $seen = LlmInvocationCancelScope::currentRunId();
            LlmInvocationCancelScope::enter(new NullCancellationToken(), 'run-sibling');
            try {
                $gate->complete();

                return $seen;
            } finally {
                LlmInvocationCancelScope::leave();
            }
        });

        $this->assertNull($sibling->await(), 'A sibling must not see a suspended owner scope.');
        $this->assertSame('run-owner', $owner->await(), 'The owner scope must survive its await.');
        $this->assertNull(LlmInvocationCancelScope::current());
    }

    public function testUnbalancedScopeIsDroppedWhenItsCallbackReturns(): void
    {
        $seen = false;
        EventLoop::defer(static function (): void {
            LlmInvocationCancelScope::enter(new NullCancellationToken(), 'run-leaked');
        });
        EventLoop::defer(static function () use (&$seen): void {
            $seen = LlmInvocationCancelScope::current();
        });

        EventLoop::run();

        $this->assertNull($seen, 'A scope left open by an earlier callback must not reach the next one.');
    }

    public function testMainScopeSurvivesEventLoopRunStartedFromMain(): void
    {
        $mainToken = new NullCancellationToken();
        LlmInvocationCancelScope::enter($mainToken, 'run-main');
        try {
            $seen = async(static fn (): ?string => LlmInvocationCancelScope::currentRunId())->await();

            $this->assertNull($seen, 'Loop callbacks must not inherit the main scope.');
            $this->assertSame($mainToken, LlmInvocationCancelScope::current());
            $this->assertSame('run-main', LlmInvocationCancelScope::currentRunId());
        } finally {
            LlmInvocationCancelScope::leave();
        }
    }

    private function clearDefaultScope(): void
    {
        while (null !== LlmInvocationCancelScope::current()) {
            LlmInvocationCancelScope::leave();
        }
    }
}
