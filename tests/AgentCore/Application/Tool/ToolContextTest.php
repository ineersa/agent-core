<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Tests\Application\Tool;

use Ineersa\AgentCore\Application\Tool\ToolContext;
use Ineersa\AgentCore\Contract\Hook\CancellationTokenInterface;
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


}
