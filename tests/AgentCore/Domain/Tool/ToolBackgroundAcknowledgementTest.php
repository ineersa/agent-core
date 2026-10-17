<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Tests\Domain\Tool;

use Ineersa\AgentCore\Domain\Tool\ToolBackgroundAcknowledgement;
use PHPUnit\Framework\TestCase;

final class ToolBackgroundAcknowledgementTest extends TestCase
{
    public function testPayloadKeepsRunningInBackgroundStatus(): void
    {
        $ack = new ToolBackgroundAcknowledgement(
            invocationId: 'inv-1',
            originRunId: 'run-1',
            toolCallId: 'call-1',
            tool: 'bash',
            inspection: ['tool' => 'bg_status', 'invocation_id' => 'inv-1', 'pid' => 12],
        );

        $this->assertSame('running_in_background', $ack->status);
        $this->assertSame('running_in_background', $ack->toResultPayload()['status']);
        $this->assertArrayHasKey('inspection', $ack->toResultPayload());
    }

    public function testRejectsNonRunningInBackgroundStatus(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ToolBackgroundAcknowledgement(
            invocationId: 'inv-1',
            originRunId: 'run-1',
            toolCallId: 'call-1',
            tool: 'bash',
            status: 'success',
        );
    }
}
