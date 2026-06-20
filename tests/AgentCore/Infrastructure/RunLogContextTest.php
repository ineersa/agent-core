<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Tests\Infrastructure;

use Ineersa\AgentCore\Infrastructure\RunLogContext;
use PHPUnit\Framework\TestCase;

final class RunLogContextTest extends TestCase
{
    protected function setUp(): void
    {
        RunLogContext::reset();
    }

    protected function tearDown(): void
    {
        RunLogContext::reset();
    }

    public function testEmptyOutsideAnyScope(): void
    {
        $this->assertSame([], RunLogContext::current());
    }

    public function testEnterAndLeavePushesAndPopsContext(): void
    {
        RunLogContext::enter(['run_id' => 'run-1', 'session_id' => 'run-1']);
        $this->assertSame('run-1', RunLogContext::current()['run_id']);

        RunLogContext::leave();
        $this->assertSame([], RunLogContext::current());
    }

    public function testNestedScopesMergeContext(): void
    {
        RunLogContext::enter(['run_id' => 'run-1', 'component' => 'runtime']);
        $this->assertSame('run-1', RunLogContext::current()['run_id']);
        $this->assertSame('runtime', RunLogContext::current()['component']);

        RunLogContext::enter(['handler' => 'StartRunHandler', 'component' => 'messenger']);
        $this->assertSame('run-1', RunLogContext::current()['run_id']);
        $this->assertSame('messenger', RunLogContext::current()['component']);
        $this->assertSame('StartRunHandler', RunLogContext::current()['handler']);

        RunLogContext::leave(); // inner scope
        $this->assertSame('run-1', RunLogContext::current()['run_id']);
        $this->assertSame('runtime', RunLogContext::current()['component']);
        $this->assertArrayNotHasKey('handler', RunLogContext::current());

        RunLogContext::leave(); // outer scope
        $this->assertSame([], RunLogContext::current());
    }

    public function testScopedWrapsOperationWithTryFinally(): void
    {
        $result = RunLogContext::scoped(
            ['run_id' => 'run-1'],
            function (): string {
                $this->assertSame('run-1', RunLogContext::current()['run_id']);

                return 'success';
            },
        );

        $this->assertSame('success', $result);
        $this->assertSame([], RunLogContext::current());
    }

    public function testScopedRestoresContextAfterException(): void
    {
        $this->expectException(\RuntimeException::class);

        try {
            RunLogContext::scoped(
                ['run_id' => 'run-1'],
                function (): never {
                    throw new \RuntimeException('test error');
                },
            );
        } finally {
            // Context should be restored even after exception
            $this->assertSame([], RunLogContext::current());
        }
    }

    public function testMultipleLeavesDoNotUnderflow(): void
    {
        RunLogContext::enter(['run_id' => 'run-1']);
        RunLogContext::leave();
        RunLogContext::leave(); // extra leave
        $this->assertSame([], RunLogContext::current());
    }

    public function testLaterScopeOverridesEarlierValues(): void
    {
        RunLogContext::enter(['run_id' => 'run-1', 'component' => 'runtime']);
        RunLogContext::enter(['component' => 'llm']);

        $current = RunLogContext::current();
        $this->assertSame('run-1', $current['run_id']);
        $this->assertSame('llm', $current['component']);

        RunLogContext::leave();
        $this->assertSame('runtime', RunLogContext::current()['component']);
    }

    public function testResetClearsStack(): void
    {
        RunLogContext::enter(['run_id' => 'run-1']);
        RunLogContext::enter(['handler' => 'Test']);

        RunLogContext::reset();

        $this->assertSame([], RunLogContext::current());
    }

    public function testDuplicateEnterIsMerged(): void
    {
        RunLogContext::enter(['run_id' => 'run-1', 'component' => 'runtime']);
        RunLogContext::enter(['run_id' => 'run-2']);

        // Inner scope overrides run_id from outer
        $this->assertSame('run-2', RunLogContext::current()['run_id']);

        RunLogContext::leave();
        $this->assertSame('run-1', RunLogContext::current()['run_id']);
    }

    /* ----- Fiber-specific tests ----- */

    public function testFiberSeesOwnContextNotMainContext(): void
    {
        RunLogContext::enter(['run_id' => 'main-run', 'component' => 'runtime']);

        $fiber = new \Fiber(function (): void {
            // Fiber should NOT see the main context
            self::assertSame([], RunLogContext::current(), 'Fiber must not inherit main thread context');

            // Fiber enters its own context
            RunLogContext::enter(['run_id' => 'fiber-run', 'component' => 'llm']);
            self::assertSame('fiber-run', RunLogContext::current()['run_id']);
            self::assertSame('llm', RunLogContext::current()['component']);

            // Suspend and verify context preserved on resume
            \Fiber::suspend();

            self::assertSame('fiber-run', RunLogContext::current()['run_id'], 'Fiber context must survive suspend/resume');

            RunLogContext::leave();
            self::assertSame([], RunLogContext::current(), 'Fiber stack must be empty after leave');
        });

        // Start the fiber (runs until first suspend)
        $fiber->start();

        // Main thread context should be untouched
        self::assertSame('main-run', RunLogContext::current()['run_id']);

        // Resume the fiber
        $fiber->resume();

        // Main thread context still unchanged
        self::assertSame('main-run', RunLogContext::current()['run_id']);

        RunLogContext::leave();
    }

    public function testTwoFibersHaveIsolatedContexts(): void
    {
        $fiber1 = new \Fiber(function (): void {
            RunLogContext::enter(['run_id' => 'fiber-1', 'component' => 'tool']);
            \Fiber::suspend();
            self::assertSame('fiber-1', RunLogContext::current()['run_id']);
            RunLogContext::leave();
        });

        $fiber2 = new \Fiber(function (): void {
            RunLogContext::enter(['run_id' => 'fiber-2', 'component' => 'llm']);
            RunLogContext::enter(['model' => 'gpt-4']);
            \Fiber::suspend();
            $ctx = RunLogContext::current();
            self::assertSame('fiber-2', $ctx['run_id']);
            self::assertSame('llm', $ctx['component']);
            self::assertSame('gpt-4', $ctx['model']);
            RunLogContext::leave();
            RunLogContext::leave();
        });

        // Start both fibers
        $fiber1->start();
        $fiber2->start();

        // Main thread has no context
        self::assertSame([], RunLogContext::current());

        // Resume and finish both
        $fiber1->resume();
        $fiber2->resume();

        self::assertTrue($fiber1->isTerminated());
        self::assertTrue($fiber2->isTerminated());
    }

    public function testFiberContextSurvivesMultipleSuspendResumeCycles(): void
    {
        $fiber = new \Fiber(function (): void {
            RunLogContext::enter(['run_id' => 'fiber-run', 'step' => 0]);

            // First suspend: context has step=0
            \Fiber::suspend();
            self::assertSame('fiber-run', RunLogContext::current()['run_id']);
            self::assertSame(0, RunLogContext::current()['step']);

            // Add another scope layer and suspend again
            RunLogContext::enter(['step' => 1]);
            \Fiber::suspend();
            self::assertSame(1, RunLogContext::current()['step']);
            self::assertSame('fiber-run', RunLogContext::current()['run_id']);

            RunLogContext::leave();
            \Fiber::suspend();
            self::assertSame(0, RunLogContext::current()['step']);

            RunLogContext::leave();
        });

        // Cycle 1: start
        $fiber->start();
        $this->assertSame([], RunLogContext::current());

        // Cycle 2: resume, check step=0, go to step=1
        $fiber->resume();
        $this->assertSame([], RunLogContext::current());

        // Cycle 3: step=1 scope active
        $fiber->resume();
        $this->assertSame([], RunLogContext::current());

        // Cycle 4: back to step=0
        $fiber->resume();

        self::assertTrue($fiber->isTerminated());
    }

    public function testFiberResetDoesNotAffectMainContext(): void
    {
        // Set context on main thread
        RunLogContext::enter(['run_id' => 'main-run']);

        $fiber = new \Fiber(function (): void {
            // Set and reset fiber context
            RunLogContext::enter(['run_id' => 'fiber-only']);
            RunLogContext::reset();
            self::assertSame([], RunLogContext::current(), 'Fiber must be empty after reset');
        });

        $fiber->start();

        // Main context must survive fiber's reset
        self::assertSame('main-run', RunLogContext::current()['run_id']);

        RunLogContext::leave();
    }

    public function testFiberContextDoesNotLeakToMainAfterFiberFinishes(): void
    {
        $fiber = new \Fiber(function (): void {
            RunLogContext::enter(['run_id' => 'fiber-run']);
            \Fiber::suspend();
            // Leave happens naturally as fiber finishes
            RunLogContext::leave();
        });

        $fiber->start();
        $fiber->resume();

        // Fiber finished. Main thread should be empty.
        self::assertSame([], RunLogContext::current());
        self::assertTrue($fiber->isTerminated());
    }
}
