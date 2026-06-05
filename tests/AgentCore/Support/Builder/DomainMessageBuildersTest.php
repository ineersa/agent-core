<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Tests\Support\Builder;

use Ineersa\AgentCore\Domain\Message\AdvanceRun;
use Ineersa\AgentCore\Domain\Message\AgentMessage;
use Ineersa\AgentCore\Domain\Message\StartRun;
use Ineersa\AgentCore\Domain\Message\ToolCallResult;
use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\AgentCore\Domain\Tool\ToolCall;
use Ineersa\AgentCore\Domain\Tool\ToolExecutionMode;
use PHPUnit\Framework\TestCase;

final class DomainMessageBuildersTest extends TestCase
{
    /* ── RunStateBuilder ── */

    public function testRunStateBuilderDefaults(): void
    {
        $state = RunStateBuilder::create()->build();

        $this->assertSame('run-test', $state->runId);
        $this->assertSame(RunStatus::Queued, $state->status);
        $this->assertSame(0, $state->version);
        $this->assertSame(0, $state->turnNo);
        $this->assertSame(0, $state->lastSeq);
        $this->assertFalse($state->isStreaming);
        $this->assertNull($state->streamingMessage);
        $this->assertSame([], $state->pendingToolCalls);
        $this->assertNull($state->errorMessage);
        $this->assertSame([], $state->messages);
        $this->assertNull($state->activeStepId);
        $this->assertFalse($state->retryableFailure);
    }

    public function testRunStateBuilderQueuedFactory(): void
    {
        $state = RunStateBuilder::queued('run-custom')->build();

        $this->assertSame('run-custom', $state->runId);
        $this->assertSame(RunStatus::Queued, $state->status);
    }

    public function testRunStateBuilderRunningFactory(): void
    {
        $state = RunStateBuilder::running('run-custom')->build();

        $this->assertSame('run-custom', $state->runId);
        $this->assertSame(RunStatus::Running, $state->status);
    }

    public function testRunStateBuilderChainableOverrides(): void
    {
        $msg = new AgentMessage(role: 'assistant', content: [['type' => 'text', 'text' => 'hi']]);

        $state = RunStateBuilder::create()
            ->withRunId('run-override')
            ->withStatus(RunStatus::Running)
            ->withVersion(5)
            ->withTurnNo(2)
            ->withLastSeq(10)
            ->withIsStreaming(true)
            ->withStreamingMessage(['chunk' => 'data'])
            ->withPendingToolCalls(['call-a' => false])
            ->withErrorMessage('something broke')
            ->withMessages([$msg])
            ->withActiveStepId('step-42')
            ->withRetryableFailure(true)
            ->build();

        $this->assertSame('run-override', $state->runId);
        $this->assertSame(RunStatus::Running, $state->status);
        $this->assertSame(5, $state->version);
        $this->assertSame(2, $state->turnNo);
        $this->assertSame(10, $state->lastSeq);
        $this->assertTrue($state->isStreaming);
        $this->assertSame(['chunk' => 'data'], $state->streamingMessage);
        $this->assertSame(['call-a' => false], $state->pendingToolCalls);
        $this->assertSame('something broke', $state->errorMessage);
        $this->assertCount(1, $state->messages);
        $this->assertSame('hi', $state->messages[0]->content[0]['text']);
        $this->assertSame('step-42', $state->activeStepId);
        $this->assertTrue($state->retryableFailure);
    }

    public function testRunStateBuilderAppendMessage(): void
    {
        $state = RunStateBuilder::create()
            ->withAppendMessage(new AgentMessage(role: 'user', content: [['type' => 'text', 'text' => 'first']]))
            ->withAppendMessage(new AgentMessage(role: 'assistant', content: [['type' => 'text', 'text' => 'second']]))
            ->build();

        $this->assertCount(2, $state->messages);
        $this->assertSame('first', $state->messages[0]->content[0]['text']);
        $this->assertSame('second', $state->messages[1]->content[0]['text']);
    }

    /* ── StartRunMessageBuilder ── */

    public function testStartRunBuilderDefaults(): void
    {
        $message = StartRunMessageBuilder::create()->build();

        $this->assertInstanceOf(StartRun::class, $message);
        $this->assertSame('run-test', $message->runId());
        $this->assertSame(0, $message->turnNo());
        $this->assertSame('start-step-1', $message->stepId());
        $this->assertSame(1, $message->attempt());
        $this->assertNotEmpty($message->idempotencyKey());
        $this->assertSame('', $message->payload->systemPrompt);
        $this->assertCount(0, $message->payload->messages);
    }

    public function testStartRunBuilderChainable(): void
    {
        $message = StartRunMessageBuilder::create('run-custom')
            ->withTurnNo(1)
            ->withStepId('custom-step')
            ->withAttempt(2)
            ->withIdempotencyKey('ik-custom')
            ->withSystemPrompt('You are helpful')
            ->withUserTextMessage('hello')
            ->build();

        $this->assertSame('run-custom', $message->runId());
        $this->assertSame(1, $message->turnNo());
        $this->assertSame('custom-step', $message->stepId());
        $this->assertSame(2, $message->attempt());
        $this->assertSame('ik-custom', $message->idempotencyKey());
        $this->assertSame('You are helpful', $message->payload->systemPrompt);
        $this->assertCount(1, $message->payload->messages);
        $this->assertSame('user', $message->payload->messages[0]->role);
    }

    /* ── AdvanceRunMessageBuilder ── */

    public function testAdvanceRunBuilderDefaults(): void
    {
        $message = AdvanceRunMessageBuilder::create()->build();

        $this->assertInstanceOf(AdvanceRun::class, $message);
        $this->assertSame('run-test', $message->runId());
        $this->assertSame(0, $message->turnNo());
        $this->assertSame('turn-1-step', $message->stepId());
        $this->assertSame(1, $message->attempt());
        $this->assertNotEmpty($message->idempotencyKey());
        $this->assertSame([], $message->payload);
    }

    public function testAdvanceRunBuilderChainable(): void
    {
        $message = AdvanceRunMessageBuilder::create()
            ->withRunId('run-adv')
            ->withTurnNo(3)
            ->withStepId('adv-step')
            ->withAttempt(2)
            ->withIdempotencyKey('ik-adv')
            ->withPayload(['reason' => 'continue'])
            ->build();

        $this->assertSame('run-adv', $message->runId());
        $this->assertSame(3, $message->turnNo());
        $this->assertSame('adv-step', $message->stepId());
        $this->assertSame(2, $message->attempt());
        $this->assertSame('ik-adv', $message->idempotencyKey());
        $this->assertSame(['reason' => 'continue'], $message->payload);
    }

    /* ── ToolCallBuilder ── */

    public function testToolCallBuilderDefaults(): void
    {
        $toolCall = ToolCallBuilder::create()->build();

        $this->assertInstanceOf(ToolCall::class, $toolCall);
        $this->assertSame('tool-call-1', $toolCall->toolCallId);
        $this->assertSame('web_search', $toolCall->toolName);
        $this->assertSame(['query' => 'test'], $toolCall->arguments);
        $this->assertSame(0, $toolCall->orderIndex);
        $this->assertNull($toolCall->runId);
        $this->assertNull($toolCall->mode);
        $this->assertNull($toolCall->timeoutSeconds);
        $this->assertNull($toolCall->toolIdempotencyKey);
        $this->assertSame([], $toolCall->context);
    }

    public function testToolCallBuilderChainable(): void
    {
        $toolCall = ToolCallBuilder::create('call-42')
            ->withToolName('read')
            ->withArguments(['path' => 'file.txt'])
            ->withOrderIndex(2)
            ->withRunId('run-1')
            ->withMode(ToolExecutionMode::Interrupt)
            ->withTimeoutSeconds(60)
            ->withToolIdempotencyKey('idem-abc')
            ->withContext(['turn_no' => 3])
            ->build();

        $this->assertSame('call-42', $toolCall->toolCallId);
        $this->assertSame('read', $toolCall->toolName);
        $this->assertSame(['path' => 'file.txt'], $toolCall->arguments);
        $this->assertSame(2, $toolCall->orderIndex);
        $this->assertSame('run-1', $toolCall->runId);
        $this->assertSame(ToolExecutionMode::Interrupt, $toolCall->mode);
        $this->assertSame(60, $toolCall->timeoutSeconds);
        $this->assertSame('idem-abc', $toolCall->toolIdempotencyKey);
        $this->assertSame(['turn_no' => 3], $toolCall->context);
    }

    /* ── ToolCallResultBuilder ── */

    public function testToolCallResultBuilderDefaults(): void
    {
        $result = ToolCallResultBuilder::create()->build();

        $this->assertInstanceOf(ToolCallResult::class, $result);
        $this->assertSame('run-test', $result->runId());
        $this->assertSame(1, $result->turnNo());
        $this->assertSame('step-1', $result->stepId());
        $this->assertSame(1, $result->attempt());
        $this->assertNotEmpty($result->idempotencyKey());
        $this->assertSame('tool-call-1', $result->toolCallId);
        $this->assertSame(0, $result->orderIndex);
        $this->assertSame(['tool_name' => 'web_search', 'content' => [['type' => 'text', 'text' => 'ok']]], $result->result);
        $this->assertFalse($result->isError);
        $this->assertNull($result->error);
    }

    public function testToolCallResultBuilderSuccessFactory(): void
    {
        $result = ToolCallResultBuilder::success('run-success')
            ->withToolCallId('call-a')
            ->withResult(['tool_name' => 'alpha', 'content' => [['type' => 'text', 'text' => 'A']]])
            ->build();

        $this->assertSame('run-success', $result->runId());
        $this->assertSame('call-a', $result->toolCallId);
        $this->assertFalse($result->isError);
        $this->assertNull($result->error);
        $this->assertSame('A', $result->result['content'][0]['text']);
    }

    public function testToolCallResultBuilderErrorFactory(): void
    {
        $result = ToolCallResultBuilder::error('run-err', 'Something went wrong')
            ->withToolCallId('call-b')
            ->build();

        $this->assertSame('run-err', $result->runId());
        $this->assertSame('call-b', $result->toolCallId);
        $this->assertTrue($result->isError);
        $this->assertSame(['message' => 'Something went wrong'], $result->error);
        $this->assertNull($result->result);
    }

    public function testToolCallResultBuilderChainable(): void
    {
        $result = ToolCallResultBuilder::create('run-override')
            ->withTurnNo(2)
            ->withStepId('custom-step')
            ->withAttempt(3)
            ->withIdempotencyKey('ik-result')
            ->withToolCallId('call-c')
            ->withOrderIndex(5)
            ->withResult(['status' => 'done'])
            ->withIsError(false)
            ->withError(null)
            ->build();

        $this->assertSame('run-override', $result->runId());
        $this->assertSame(2, $result->turnNo());
        $this->assertSame('custom-step', $result->stepId());
        $this->assertSame(3, $result->attempt());
        $this->assertSame('ik-result', $result->idempotencyKey());
        $this->assertSame('call-c', $result->toolCallId);
        $this->assertSame(5, $result->orderIndex);
        $this->assertSame(['status' => 'done'], $result->result);
        $this->assertFalse($result->isError);
        $this->assertNull($result->error);
    }
}
