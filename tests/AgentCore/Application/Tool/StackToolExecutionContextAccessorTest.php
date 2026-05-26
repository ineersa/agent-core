<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Tests\Application\Tool;

use Ineersa\AgentCore\Application\Tool\StackToolExecutionContextAccessor;
use Ineersa\AgentCore\Application\Tool\ToolContext;
use Ineersa\AgentCore\Contract\Hook\CancellationTokenInterface;
use PHPUnit\Framework\TestCase;

final class StackToolExecutionContextAccessorTest extends TestCase
{
    public function testCurrentReturnsNullWhenNoContext(): void
    {
        $accessor = new StackToolExecutionContextAccessor();

        self::assertNull($accessor->current());
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

        $result = $accessor->with($context, function () use ($accessor, $context): string {
            self::assertSame($context, $accessor->current());

            return 'done';
        });

        self::assertSame('done', $result);
        self::assertNull($accessor->current());
    }

    public function testWithPopsContextOnException(): void
    {
        $accessor = new StackToolExecutionContextAccessor();
        $context = $this->createContext('run-a', 1, 'call-1', 'test_tool');

        try {
            $accessor->with($context, function () use ($accessor): never {
                throw new \RuntimeException('test error');
            });
        } catch (\RuntimeException) {
            // Expected.
        }

        self::assertNull($accessor->current());
    }

    public function testNestedContext(): void
    {
        $accessor = new StackToolExecutionContextAccessor();
        $outer = $this->createContext('run-a', 1, 'call-1', 'outer');
        $inner = $this->createContext('run-a', 2, 'call-2', 'inner');

        $result = $accessor->with($outer, function () use ($accessor, $inner): string {
            return $accessor->with($inner, function () use ($accessor): string {
                return $accessor->requireCurrent()->toolName();
            });
        });

        self::assertSame('inner', $result);
        self::assertNull($accessor->current());
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
                public function isCancellationRequested(): bool { return false; }
            },
            timeoutSeconds: 30,
        );
    }
}
