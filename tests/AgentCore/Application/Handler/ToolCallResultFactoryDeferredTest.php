<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Tests\Application\Handler;

use Ineersa\AgentCore\Application\Handler\ToolCallResultFactory;
use Ineersa\AgentCore\Domain\Tool\DeferredToolCompletionCorrelation;
use PHPUnit\Framework\TestCase;

final class ToolCallResultFactoryDeferredTest extends TestCase
{
    public function testFromDeferredCorrelationPromotesDetailsToolIdempotencyKey(): void
    {
        $correlation = new DeferredToolCompletionCorrelation(
            deferredId: 'd1',
            runId: 'run-1',
            turnNo: 1,
            stepId: 'step-1',
            attempt: 1,
            idempotencyKey: 'msg-key',
            toolCallId: 'call-1',
            toolName: 'subagent',
            arguments: [],
            orderIndex: 0,
            toolIdempotencyKey: 'stored-key',
        );

        $result = ToolCallResultFactory::fromDeferredCorrelationAndCompletion(
            $correlation,
            [['type' => 'text', 'text' => 'done']],
            details: ['tool_idempotency_key' => 'custom-key'],
        );

        $this->assertSame('custom-key', $result->result['tool_idempotency_key']);
    }
}
