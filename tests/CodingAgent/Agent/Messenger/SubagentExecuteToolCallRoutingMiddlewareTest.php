<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Agent\Messenger;

use Ineersa\AgentCore\Domain\Message\ExecuteToolCall;
use Ineersa\CodingAgent\Agent\Messenger\SubagentExecuteToolCallRoutingMiddleware;
use Ineersa\CodingAgent\Tests\Support\Messenger\TestStack;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;

/**
 * Test thesis: subagent ExecuteToolCall is stamped to agent transport; other tools
 * and already-routed envelopes pass through unchanged.
 */
final class SubagentExecuteToolCallRoutingMiddlewareTest extends TestCase
{
    public function testStampsAgentTransportForSubagentTool(): void
    {
        $middleware = new SubagentExecuteToolCallRoutingMiddleware();
        $message = $this->makeExecuteToolCall('subagent');
        $envelope = new Envelope($message);

        $result = $middleware->handle($envelope, new TestStack());

        $stamp = $result->last(TransportNamesStamp::class);
        $this->assertNotNull($stamp);
        $this->assertSame(['agent'], $stamp->getTransportNames());
    }

    public function testDoesNotStampNonSubagentTool(): void
    {
        $middleware = new SubagentExecuteToolCallRoutingMiddleware();
        $message = $this->makeExecuteToolCall('read');
        $envelope = new Envelope($message);

        $result = $middleware->handle($envelope, new TestStack());

        $this->assertNull($result->last(TransportNamesStamp::class));
    }

    public function testSkipsMessagesWithReceivedStamp(): void
    {
        $middleware = new SubagentExecuteToolCallRoutingMiddleware();
        $message = $this->makeExecuteToolCall('subagent');
        $envelope = (new Envelope($message))->with(new ReceivedStamp('agent'));

        $result = $middleware->handle($envelope, new TestStack());

        $this->assertNotNull($result->last(ReceivedStamp::class));
        $this->assertNull($result->last(TransportNamesStamp::class));
    }

    public function testSkipsMessagesWithExistingTransportNamesStamp(): void
    {
        $middleware = new SubagentExecuteToolCallRoutingMiddleware();
        $message = $this->makeExecuteToolCall('subagent');
        $envelope = (new Envelope($message))->with(new TransportNamesStamp(['tool']));

        $result = $middleware->handle($envelope, new TestStack());

        $stamp = $result->last(TransportNamesStamp::class);
        $this->assertNotNull($stamp);
        $this->assertSame(['tool'], $stamp->getTransportNames());
    }

    public function testIgnoresNonExecuteToolCallMessages(): void
    {
        $middleware = new SubagentExecuteToolCallRoutingMiddleware();
        $envelope = new Envelope(new \stdClass());

        $result = $middleware->handle($envelope, new TestStack());

        $this->assertNull($result->last(TransportNamesStamp::class));
    }

    private function makeExecuteToolCall(string $toolName): ExecuteToolCall
    {
        return new ExecuteToolCall(
            runId: 'run-1',
            turnNo: 1,
            stepId: 's1',
            attempt: 1,
            idempotencyKey: 'ik1',
            toolCallId: 'tc1',
            toolName: $toolName,
            args: [],
            orderIndex: 0,
        );
    }
}
