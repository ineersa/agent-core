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
}
