<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Tool;

use Ineersa\AgentCore\Application\Tool\StackToolExecutionContextAccessor;
use Ineersa\AgentCore\Application\Tool\ToolContext;
use Ineersa\AgentCore\Contract\Hook\CancellationTokenInterface;
use Ineersa\CodingAgent\Tool\ToolRuntime;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Ineersa\CodingAgent\Tool\ToolRuntime
 */
final class ToolRuntimeTest extends TestCase
{
    private StackToolExecutionContextAccessor $contextAccessor;
    private ToolRuntime $toolRuntime;

    protected function setUp(): void
    {
        $this->contextAccessor = new StackToolExecutionContextAccessor();
        $this->toolRuntime = new ToolRuntime($this->contextAccessor);
    }

    public function testRunReturnsCallbackResult(): void
    {
        $token = $this->createToken(false);

        $result = $this->contextAccessor->with(
            $this->contextWithToken($token),
            fn (): string => $this->toolRuntime->run(static fn (): string => 'completed'),
        );

        $this->assertSame('completed', $result);
    }

    public function testRunThrowsWhenCancelledBefore(): void
    {
        $token = $this->createToken(true);

        $this->contextAccessor->with(
            $this->contextWithToken($token),
            function (): void {
                $this->expectException(\RuntimeException::class);
                $this->expectExceptionMessage('cancelled before start');
                $this->toolRuntime->run(static fn (): string => 'unreachable');
            },
        );
    }

    public function testRunThrowsWhenCancelledAfter(): void
    {
        $token = $this->createMock(CancellationTokenInterface::class);
        $token->expects($this->exactly(2))
            ->method('isCancellationRequested')
            ->willReturnOnConsecutiveCalls(false, true);

        $this->contextAccessor->with(
            $this->contextWithToken($token),
            function (): void {
                $this->expectException(\RuntimeException::class);
                $this->expectExceptionMessage('stale due to run cancellation');
                $this->toolRuntime->run(static fn (): string => 'result');
            },
        );
    }

    public function testRunWithoutContextSucceeds(): void
    {
        // No context on the stack — current() returns null.
        $result = $this->toolRuntime->run(static fn (): string => 'ok');

        $this->assertSame('ok', $result);
    }

    private function createToken(bool $cancelled): CancellationTokenInterface
    {
        $token = $this->createStub(CancellationTokenInterface::class);
        $token->method('isCancellationRequested')->willReturn($cancelled);

        return $token;
    }

    private function contextWithToken(CancellationTokenInterface $token): ToolContext
    {
        return new ToolContext(
            runId: 'run_1',
            turnNo: 1,
            toolCallId: 'call_1',
            toolName: 'test_tool',
            cancellationToken: $token,
            timeoutSeconds: 30,
        );
    }
}
