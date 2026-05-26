<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Tests\Application\Tool;

use Ineersa\AgentCore\Application\Tool\ToolContext;
use Ineersa\AgentCore\Contract\Hook\CancellationTokenInterface;
use Ineersa\AgentCore\Contract\Tool\ToolCancelledException;
use PHPUnit\Framework\TestCase;

final class ToolContextTest extends TestCase
{
    public function testGetters(): void
    {
        $token = new class implements CancellationTokenInterface {
            public function isCancellationRequested(): bool { return false; }
        };

        $context = new ToolContext(
            runId: 'run-1',
            turnNo: 3,
            toolCallId: 'call-42',
            toolName: 'read',
            cancellationToken: $token,
            timeoutSeconds: 60,
        );

        self::assertSame('run-1', $context->runId());
        self::assertSame(3, $context->turnNo());
        self::assertSame('call-42', $context->toolCallId());
        self::assertSame('read', $context->toolName());
        self::assertSame($token, $context->cancellationToken());
        self::assertSame(60, $context->timeoutSeconds());
    }

    public function testThrowIfCancellationRequestedDoesNotThrowWhenNotCancelled(): void
    {
        $token = new class implements CancellationTokenInterface {
            public function isCancellationRequested(): bool { return false; }
        };

        $context = new ToolContext('run-1', 1, 'c-1', 't', $token, 30);

        // Should not throw.
        $context->throwIfCancellationRequested();
        $this->expectNotToPerformAssertions();
    }

    public function testThrowIfCancellationRequestedThrowsWhenCancelled(): void
    {
        $token = new class implements CancellationTokenInterface {
            public function isCancellationRequested(): bool { return true; }
        };

        $context = new ToolContext('run-1', 1, 'c-1', 't', $token, 30);

        $this->expectException(ToolCancelledException::class);
        $context->throwIfCancellationRequested();
    }
}
