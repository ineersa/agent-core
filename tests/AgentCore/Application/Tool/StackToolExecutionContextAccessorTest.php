<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Tests\Application\Tool;

use Amp\DeferredFuture;
use Ineersa\AgentCore\Application\Tool\StackToolExecutionContextAccessor;
use Ineersa\AgentCore\Application\Tool\ToolContext;
use Ineersa\AgentCore\Contract\Hook\CancellationTokenInterface;
use Ineersa\AgentCore\Tests\Support\IsolatedEventLoop;
use PHPUnit\Framework\TestCase;

use function Amp\async;

final class StackToolExecutionContextAccessorTest extends TestCase
{
    use IsolatedEventLoop;

    public function testCurrentReturnsNullWhenNoContext(): void
    {
        $accessor = new StackToolExecutionContextAccessor();

        $this->assertNull($accessor->current());
        $this->assertSame(0, $accessor->depth());
    }

    public function testRequireCurrentThrowsWhenNoContext(): void
    {
        $accessor = new StackToolExecutionContextAccessor();

        $this->expectException(\LogicException::class);
        $accessor->requireCurrent();
    }

    public function testWithPushesAndPopsContext(): void
    {
        $accessor = new StackToolExecutionContextAccessor();
        $context = $this->createContext('run-a', 1, 'call-1', 'test_tool');

        $result = $accessor->with($context, static function () use ($accessor, $context): string {
            self::assertSame($context, $accessor->current());

            return 'done';
        });

        $this->assertSame('done', $result);
        $this->assertNull($accessor->current());
    }

    public function testWithPopsContextOnException(): void
    {
        $accessor = new StackToolExecutionContextAccessor();
        $context = $this->createContext('run-a', 1, 'call-1', 'test_tool');

        try {
            $accessor->with($context, static function (): never {
                throw new \RuntimeException('test error');
            });
        } catch (\RuntimeException) {
            // Expected.
        }

        $this->assertNull($accessor->current());
    }

    public function testNestedContext(): void
    {
        $accessor = new StackToolExecutionContextAccessor();
        $outer = $this->createContext('run-a', 1, 'call-1', 'outer');
        $inner = $this->createContext('run-a', 2, 'call-2', 'inner');

        $result = $accessor->with($outer, static function () use ($accessor, $inner): string {
            self::assertSame(1, $accessor->depth());

            return $accessor->with($inner, static function () use ($accessor): string {
                self::assertSame(2, $accessor->depth());

                return $accessor->requireCurrent()->toolName();
            });
        });

        $this->assertSame('inner', $result);
        $this->assertNull($accessor->current());
    }

    public function testFibersAreIsolatedFromMainAndEachOtherAcrossSuspend(): void
    {
        $accessor = new StackToolExecutionContextAccessor();
        $main = $this->createContext('run-main', 1, 'call-main', 'main');
        $a = $this->createContext('run-a', 1, 'call-a', 'a');
        $b = $this->createContext('run-b', 1, 'call-b', 'b');
        $fiber = static fn (ToolContext $context): \Fiber => new \Fiber(static function () use ($accessor, $context): void {
            self::assertNull($accessor->current(), 'A Fiber must not inherit the main context.');
            $accessor->with($context, static function () use ($accessor, $context): void {
                \Fiber::suspend();
                self::assertSame($context, $accessor->current());
                self::assertSame(1, $accessor->depth());
            });
            self::assertNull($accessor->current());
        });

        $accessor->with($main, function () use ($accessor, $main, $fiber, $a, $b): void {
            $fiberA = $fiber($a);
            $fiberB = $fiber($b);
            $fiberA->start();
            $fiberB->start();
            $this->assertSame($main, $accessor->current(), 'Suspended Fibers must not expose their context to main.');
            $fiberA->resume();
            $fiberB->resume();
            $this->assertTrue($fiberA->isTerminated());
            $this->assertTrue($fiberB->isTerminated());
            $this->assertSame($main, $accessor->current());
        });
        $this->assertNull($accessor->current());
    }

    public function testExceptionInsideFiberRestoresOuterFiberContext(): void
    {
        $accessor = new StackToolExecutionContextAccessor();
        $outer = $this->createContext('run-a', 1, 'call-1', 'outer');
        $inner = $this->createContext('run-a', 2, 'call-2', 'inner');
        $failure = new \RuntimeException('cancel');

        $fiber = new \Fiber(static function () use ($accessor, $outer, $inner, $failure): void {
            $accessor->with($outer, static function () use ($accessor, $outer, $inner, $failure): void {
                try {
                    $accessor->with($inner, static function (): void {
                        \Fiber::suspend();
                    });
                    self::fail('The injected failure must propagate.');
                } catch (\RuntimeException $caught) {
                    self::assertSame($failure, $caught);
                }
                self::assertSame($outer, $accessor->current());
            });
            self::assertNull($accessor->current());
        });
        $fiber->start();
        $fiber->throw($failure);

        $this->assertTrue($fiber->isTerminated());
        $this->assertNull($accessor->current());
    }

    public function testTwoInstancesKeepIndependentStacksOnOneFiber(): void
    {
        $first = new StackToolExecutionContextAccessor();
        $second = new StackToolExecutionContextAccessor();
        $context = $this->createContext('run-a', 1, 'call-1', 'first');

        $fiber = new \Fiber(static function () use ($first, $second, $context): void {
            $first->with($context, static function () use ($first, $second, $context): void {
                self::assertSame($context, $first->current());
                self::assertNull($second->current());
                self::assertSame(0, $second->depth());
            });
        });
        $fiber->start();

        $first->with($context, static function () use ($second): void {
            self::assertNull($second->current());
        });
        $this->assertTrue($fiber->isTerminated());
    }

    public function testContextSurvivesAwaitInsideAsyncWhileSiblingRuns(): void
    {
        $accessor = new StackToolExecutionContextAccessor();
        $ownerContext = $this->createContext('run-owner', 1, 'call-1', 'owner');
        $siblingContext = $this->createContext('run-sibling', 1, 'call-2', 'sibling');
        $gate = new DeferredFuture();
        $owner = async(static fn (): ?ToolContext => $accessor->with($ownerContext, static function () use ($accessor, $gate): ?ToolContext {
            $gate->getFuture()->await();

            return $accessor->current();
        }));
        $sibling = async(static function () use ($accessor, $siblingContext, $gate): ?ToolContext {
            $seen = $accessor->current();
            $accessor->with($siblingContext, static fn () => $gate->complete());

            return $seen;
        });

        $this->assertNull($sibling->await(), 'A sibling must not see a suspended owner context.');
        $this->assertSame($ownerContext, $owner->await(), 'The owner context must survive its await.');
        $this->assertNull($accessor->current());
    }

    public function testMainContextSurvivesEventLoopRunStartedFromMain(): void
    {
        $accessor = new StackToolExecutionContextAccessor();
        $main = $this->createContext('run-main', 1, 'call-main', 'main');

        $accessor->with($main, function () use ($accessor, $main): void {
            $seen = async(static fn (): ?ToolContext => $accessor->current())->await();

            $this->assertNull($seen, 'Loop callbacks must not inherit the main context.');
            $this->assertSame($main, $accessor->current());
        });
        $this->assertNull($accessor->current());
    }

    private function createContext(
        string $runId,
        int $turnNo,
        string $toolCallId,
        string $toolName,
    ): ToolContext {
        return new ToolContext(
            runId: $runId,
            turnNo: $turnNo,
            toolCallId: $toolCallId,
            toolName: $toolName,
            cancellationToken: new class implements CancellationTokenInterface {
                public function isCancellationRequested(): bool
                {
                    return false;
                }
            },
            timeoutSeconds: 30,
        );
    }
}
