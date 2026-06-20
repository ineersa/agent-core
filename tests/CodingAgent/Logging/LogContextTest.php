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
        self::assertSame('run-1', RunLogContext::current()['run_id']);
        self::assertSame('test', RunLogContext::current()['component']);

        LogContext::leave();
        self::assertSame([], RunLogContext::current());
    }

    public function testScopedDelegation(): void
    {
        $result = LogContext::scoped(
            ['run_id' => 'run-1'],
            static fn (): string => 'ok',
        );

        self::assertSame('ok', $result);
        self::assertSame([], RunLogContext::current());
    }

    public function testReset(): void
    {
        LogContext::enter(['run_id' => 'run-1']);
        LogContext::reset();
        self::assertSame([], RunLogContext::current());
    }

    public function testCurrentReturnsSameAsRunLogContext(): void
    {
        RunLogContext::enter(['run_id' => 'run-1']);
        self::assertSame(LogContext::current(), RunLogContext::current());
        RunLogContext::leave();
    }
}
