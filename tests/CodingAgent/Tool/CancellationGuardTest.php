<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Tool;

use Ineersa\AgentCore\Application\Tool\StackToolExecutionContextAccessor;
use Ineersa\AgentCore\Application\Tool\ToolContext;
use Ineersa\AgentCore\Contract\Hook\CancellationTokenInterface;
use Ineersa\AgentCore\Contract\Tool\ToolCancelledException;
use Ineersa\CodingAgent\Tool\CancellationGuard;
use PHPUnit\Framework\TestCase;

final class CancellationGuardTest extends TestCase
{
    private CancellationGuard $guard;

    protected function setUp(): void
    {
        $this->guard = new CancellationGuard();
    }

    public function testCheckpointDoesNotThrowWhenNotCancelled(): void
    {
        $context = $this->createContext(false);

        // Should not throw.
        $this->guard->checkpoint($context);
        $this->expectNotToPerformAssertions();
    }

    public function testCheckpointThrowsWhenCancelled(): void
    {
        $context = $this->createContext(true);

        $this->expectException(ToolCancelledException::class);
        $this->guard->checkpoint($context);
    }

    public function testCheckpointFromAccessorThrowsWhenCancelled(): void
    {
        $accessor = new StackToolExecutionContextAccessor();
        $context = $this->createContext(true);

        $accessor->with($context, function () use ($accessor): void {
            $this->expectException(ToolCancelledException::class);
            $this->guard->checkpointFromAccessor($accessor);
        });
    }

    public function testCheckpointFromAccessorDoesNotThrowWhenNotCancelled(): void
    {
        $accessor = new StackToolExecutionContextAccessor();
        $context = $this->createContext(false);

        $accessor->with($context, function () use ($accessor): void {
            $this->guard->checkpointFromAccessor($accessor);
            $this->expectNotToPerformAssertions();
        });
    }

    public function testCheckpointFromAccessorThrowsWhenNoContext(): void
    {
        $accessor = new StackToolExecutionContextAccessor();

        $this->expectException(\LogicException::class);
        $this->guard->checkpointFromAccessor($accessor);
    }

    private function createContext(bool $cancelled): ToolContext
    {
        return new ToolContext(
            runId: 'run-1',
            turnNo: 1,
            toolCallId: 'call-1',
            toolName: 'test_tool',
            cancellationToken: new class($cancelled) implements CancellationTokenInterface {
                public function __construct(private readonly bool $cancelled)
                {
                }

                public function isCancellationRequested(): bool
                {
                    return $this->cancelled;
                }
            },
            timeoutSeconds: 30,
        );
    }
}
