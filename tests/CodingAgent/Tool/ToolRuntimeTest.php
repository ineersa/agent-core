<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Tool;

use Ineersa\AgentCore\Application\Tool\StackToolExecutionContextAccessor;
use Ineersa\AgentCore\Application\Tool\ToolContext;
use Ineersa\AgentCore\Contract\Hook\CancellationTokenInterface;
use Ineersa\CodingAgent\Tool\CancellableProcessResult;
use Ineersa\CodingAgent\Tool\ToolRuntime;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * @covers \Ineersa\CodingAgent\Tool\ToolRuntime
 * @covers \Ineersa\CodingAgent\Tool\CancellableProcessResult
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

    private function createToken(bool $cancelled): CancellationTokenInterface
    {
        $token = $this->createMock(CancellationTokenInterface::class);
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

    /* ────────── run() tests ────────── */

    public function testRunReturnsCallbackResult(): void
    {
        $token = $this->createToken(false);

        $result = $this->contextAccessor->with(
            $this->contextWithToken($token),
            fn (): string => $this->toolRuntime->run(fn (): string => 'completed'),
        );

        self::assertSame('completed', $result);
    }

    public function testRunThrowsWhenCancelledBefore(): void
    {
        $token = $this->createToken(true);

        $this->contextAccessor->with(
            $this->contextWithToken($token),
            function (): void {
                $this->expectException(\RuntimeException::class);
                $this->expectExceptionMessage('cancelled before start');
                $this->toolRuntime->run(fn (): string => 'unreachable');
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
                $this->toolRuntime->run(fn (): string => 'result');
            },
        );
    }

    public function testRunWithoutContextSucceeds(): void
    {
        // No context on the stack — current() returns null.
        $result = $this->toolRuntime->run(fn (): string => 'ok');

        self::assertSame('ok', $result);
    }

    /* ────────── runCancellableProcess() tests ────────── */

    public function testCancellableProcessSuccess(): void
    {
        $process = new Process(['php', '-r', 'echo "hello"; exit(0);']);

        /** @var CancellableProcessResult $result */
        $result = $this->contextAccessor->with(
            $this->contextWithToken($this->createToken(false)),
            fn (): CancellableProcessResult => $this->toolRuntime->runCancellableProcess(
                $process,
                graceSeconds: 1,
                timeoutSeconds: null,
                pollIntervalMicros: 1000,
            ),
        );

        self::assertSame('hello', $result->stdout);
        self::assertSame(0, $result->exitCode);
        self::assertFalse($result->cancelled);
        self::assertFalse($result->timedOut);
    }

    public function testCancellableProcessCancelled(): void
    {
        $process = new Process(['php', '-r', 'usleep(200000); echo "interrupted";']);

        // Cancellation token returns true — process should be stopped immediately.
        $result = $this->contextAccessor->with(
            $this->contextWithToken($this->createToken(true)),
            fn (): CancellableProcessResult => $this->toolRuntime->runCancellableProcess(
                $process,
                graceSeconds: 1,
                timeoutSeconds: null,
                pollIntervalMicros: 1000,
            ),
        );

        self::assertTrue($result->cancelled, 'Process should be marked cancelled');
        self::assertFalse($result->timedOut);
    }

    public function testCancellableProcessTimeout(): void
    {
        $process = new Process(['php', '-r', 'usleep(500000); echo "too_slow";']);

        // Timeout seconds 0 means immediate deadline.
        // The process should be running (500ms sleep) and we hit timeout immediately.
        $result = $this->contextAccessor->with(
            $this->contextWithToken($this->createToken(false)),
            fn (): CancellableProcessResult => $this->toolRuntime->runCancellableProcess(
                $process,
                graceSeconds: 1,
                timeoutSeconds: 0,
                pollIntervalMicros: 1000,
            ),
        );

        self::assertTrue($result->timedOut, 'Process should be marked timed out');
        self::assertFalse($result->cancelled);
    }

    public function testCancellableProcessWithoutContext(): void
    {
        // No context active — cancellation checks are skipped.
        $process = new Process(['php', '-r', 'echo "no_context"; exit(0);']);

        $result = $this->toolRuntime->runCancellableProcess(
            $process,
            graceSeconds: 1,
            timeoutSeconds: null,
            pollIntervalMicros: 1000,
        );

        self::assertSame('no_context', $result->stdout);
        self::assertSame(0, $result->exitCode);
        self::assertFalse($result->cancelled);
        self::assertFalse($result->timedOut);
    }

    /* ────────── CancellableProcessResult DTO tests ────────── */

    public function testCancellableProcessResultToArray(): void
    {
        $result = new CancellableProcessResult(
            stdout: 'out',
            stderr: 'err',
            exitCode: 0,
            cancelled: false,
            timedOut: false,
        );

        $array = $result->toArray();

        self::assertSame('out', $array['stdout']);
        self::assertSame('err', $array['stderr']);
        self::assertSame(0, $array['exit_code']);
        self::assertFalse($array['cancelled']);
        self::assertFalse($array['timed_out']);
    }

    public function testCancellableProcessResultDefaultValues(): void
    {
        $result = new CancellableProcessResult();

        self::assertSame('', $result->stdout);
        self::assertSame('', $result->stderr);
        self::assertNull($result->exitCode);
        self::assertFalse($result->cancelled);
        self::assertFalse($result->timedOut);
    }
}
