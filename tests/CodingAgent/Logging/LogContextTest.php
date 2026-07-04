<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Logging;

use Ineersa\AgentCore\Infrastructure\RunLogContext;
use Ineersa\CodingAgent\Logging\LogContext;
use PHPUnit\Framework\TestCase;

final class LogContextTest extends TestCase
{
    protected function setUp(): void
    {
        RunLogContext::reset();
    }

    protected function tearDown(): void
    {
        RunLogContext::reset();
    }

    public function testDelegatesToRunLogContext(): void
    {
        LogContext::enter(['run_id' => 'run-1', 'component' => 'test']);
        $this->assertSame('run-1', RunLogContext::current()['run_id']);
        $this->assertSame('test', RunLogContext::current()['component']);

        LogContext::leave();
        $this->assertSame([], RunLogContext::current());
    }

    public function testScopedDelegation(): void
    {
        $result = LogContext::scoped(
            ['run_id' => 'run-1'],
            static fn (): string => 'ok',
        );

        $this->assertSame('ok', $result);
        $this->assertSame([], RunLogContext::current());
    }

    public function testReset(): void
    {
        LogContext::enter(['run_id' => 'run-1']);
        LogContext::reset();
        $this->assertSame([], RunLogContext::current());
    }

    public function testCurrentReturnsSameAsRunLogContext(): void
    {
        RunLogContext::enter(['run_id' => 'run-1']);
        $this->assertSame(LogContext::current(), RunLogContext::current());
        RunLogContext::leave();
    }
}
