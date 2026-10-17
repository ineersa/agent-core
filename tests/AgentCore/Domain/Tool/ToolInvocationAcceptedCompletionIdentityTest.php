<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Tests\Domain\Tool;

use Ineersa\AgentCore\Domain\Tool\DeferredToolCompletionCorrelation;
use Ineersa\AgentCore\Domain\Tool\ToolInvocationAcceptedCompletionIdentity;
use PHPUnit\Framework\TestCase;

final class ToolInvocationAcceptedCompletionIdentityTest extends TestCase
{
    public function testCompletionIdIsDeterministicForInvocationIdentity(): void
    {
        $first = ToolInvocationAcceptedCompletionIdentity::completionId('run', 3, 'step', 'call');
        $second = ToolInvocationAcceptedCompletionIdentity::completionId('run', 3, 'step', 'call');
        $other = ToolInvocationAcceptedCompletionIdentity::completionId('run', 3, 'step', 'other');

        $this->assertSame($first, $second);
        $this->assertNotSame($first, $other);
        $this->assertSame(64, \strlen($first));
    }

    public function testCompletionIdFromCorrelationMatchesTuple(): void
    {
        $correlation = new DeferredToolCompletionCorrelation(
            deferredId: '550e8400-e29b-41d4-a716-446655440600',
            runId: 'run',
            turnNo: 2,
            stepId: 'step',
            attempt: 1,
            idempotencyKey: 'key',
            toolCallId: 'call',
            toolName: 'bash',
            arguments: ['command' => 'true'],
            orderIndex: 0,
        );

        $this->assertSame(
            ToolInvocationAcceptedCompletionIdentity::completionId('run', 2, 'step', 'call'),
            ToolInvocationAcceptedCompletionIdentity::completionIdFromCorrelation($correlation),
        );
    }
}
