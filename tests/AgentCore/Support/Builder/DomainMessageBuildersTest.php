<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Tests\Support\Builder;

use Ineersa\AgentCore\Domain\Message\AdvanceRun;
use Ineersa\AgentCore\Domain\Message\AgentMessage;
use Ineersa\AgentCore\Domain\Message\StartRun;
use Ineersa\AgentCore\Domain\Message\ToolCallResult;
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

        self::assertSame('run-test', $state->runId);
        self::assertSame(RunStatus::Queued, $state->status);
        self::assertSame(0, $state->version);
        self::assertSame(0, $state->turnNo);
        self::assertSame(0, $state->lastSeq);
        self::assertFalse($state->isStreaming);
        self::assertNull($state->streamingMessage);
        self::assertSame([], $state->pendingToolCalls);
        self::assertNull($state->errorMessage);
        self::assertSame([], $state->messages);
        self::assertNull($state->activeStepId);
        self::assertFalse($state->retryableFailure);
    }

    public function testRunStateBuilderQueuedFactory(): void
    {
        $state = RunStateBuilder::queued('run-custom')->build();

        self::assertSame('run-custom', $state->runId);
        self::assertSame(RunStatus::Queued, $state->status);
    }

    public function testRunStateBuilderRunningFactory(): void
    {
        $state = RunStateBuilder::running('run-custom')->build();

        self::assertSame('run-custom', $state->runId);
        self::assertSame(RunStatus::Running, $state->status);
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

        self::assertSame('run-override', $state->runId);
        self::assertSame(RunStatus::Running, $state->status);
        self::assertSame(5, $state->version);
        self::assertSame(2, $state->turnNo);
        self::assertSame(10, $state->lastSeq);
        self::assertTrue($state->isStreaming);
        self::assertSame(['chunk' => 'data'], $state->streamingMessage);
        self::assertSame(['call-a' => false], $state->pendingToolCalls);
        self::assertSame('something broke', $state->errorMessage);
        self::assertCount(1, $state->messages);
        self::assertSame('hi', $state->messages[0]->content[0]['text']);
        self::assertSame('step-42', $state->activeStepId);
        self::assertTrue($state->retryableFailure);
    }

    public function testRunStateBuilderAppendMessage(): void
    {
        $state = RunStateBuilder::create()
            ->withAppendMessage(new AgentMessage(role: 'user', content: [['type' => 'text', 'text' => 'first']]))
            ->withAppendMessage(new AgentMessage(role: 'assistant', content: [['type' => 'text', 'text' => 'second']]))
            ->build();

        self::assertCount(2, $state->messages);
        self::assertSame('first', $state->messages[0]->content[0]['text']);
        self::assertSame('second', $state->messages[1]->content[0]['text']);
    }

    /* ── StartRunMessageBuilder ── */

    public function testStartRunBuilderDefaults(): void
    {
        $message = StartRunMessageBuilder::create()->build();

        self::assertInstanceOf(StartRun::class, $message);
        self::assertSame('run-test', $message->runId());
        self::assertSame(0, $message->turnNo());
        self::assertSame('start-step-1', $message->stepId());
        self::assertSame(1, $message->attempt());
        self::assertNotEmpty($message->idempotencyKey());
        self::assertSame('', $message->payload->systemPrompt);
        self::assertCount(0, $message->payload->messages);
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

        self::assertSame('run-custom', $message->runId());
        self::assertSame(1, $message->turnNo());
        self::assertSame('custom-step', $message->stepId());
        self::assertSame(2, $message->attempt());
        self::assertSame('ik-custom', $message->idempotencyKey());
        self::assertSame('You are helpful', $message->payload->systemPrompt);
        self::assertCount(1, $message->payload->messages);
        self::assertSame('user', $message->payload->messages[0]->role);
    }

    /* ── AdvanceRunMessageBuilder ── */

    public function testAdvanceRunBuilderDefaults(): void
    {
        $message = AdvanceRunMessageBuilder::create()->build();

        self::assertInstanceOf(AdvanceRun::class, $message);
        self::assertSame('run-test', $message->runId());
        self::assertSame(0, $message->turnNo());
        self::assertSame('turn-1-step', $message->stepId());
        self::assertSame(1, $message->attempt());
        self::assertNotEmpty($message->idempotencyKey());
        self::assertSame([], $message->payload);
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

        self::assertSame('run-adv', $message->runId());
        self::assertSame(3, $message->turnNo());
        self::assertSame('adv-step', $message->stepId());
        self::assertSame(2, $message->attempt());
        self::assertSame('ik-adv', $message->idempotencyKey());
        self::assertSame(['reason' => 'continue'], $message->payload);
    }

    /* ── ToolCallBuilder ── */

    public function testToolCallBuilderDefaults(): void
    {
        $toolCall = ToolCallBuilder::create()->build();

        self::assertInstanceOf(ToolCall::class, $toolCall);
        self::assertSame('tool-call-1', $toolCall->toolCallId);
        self::assertSame('web_search', $toolCall->toolName);
        self::assertSame(['query' => 'test'], $toolCall->arguments);
        self::assertSame(0, $toolCall->orderIndex);
        self::assertNull($toolCall->runId);
        self::assertNull($toolCall->mode);
        self::assertNull($toolCall->timeoutSeconds);
        self::assertNull($toolCall->toolIdempotencyKey);
        self::assertSame([], $toolCall->context);
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

        self::assertSame('call-42', $toolCall->toolCallId);
        self::assertSame('read', $toolCall->toolName);
        self::assertSame(['path' => 'file.txt'], $toolCall->arguments);
        self::assertSame(2, $toolCall->orderIndex);
        self::assertSame('run-1', $toolCall->runId);
        self::assertSame(ToolExecutionMode::Interrupt, $toolCall->mode);
        self::assertSame(60, $toolCall->timeoutSeconds);
        self::assertSame('idem-abc', $toolCall->toolIdempotencyKey);
        self::assertSame(['turn_no' => 3], $toolCall->context);
    }

    /* ── ToolCallResultBuilder ── */

    public function testToolCallResultBuilderDefaults(): void
    {
        $result = ToolCallResultBuilder::create()->build();

        self::assertInstanceOf(ToolCallResult::class, $result);
        self::assertSame('run-test', $result->runId());
        self::assertSame(1, $result->turnNo());
        self::assertSame('step-1', $result->stepId());
        self::assertSame(1, $result->attempt());
        self::assertNotEmpty($result->idempotencyKey());
        self::assertSame('tool-call-1', $result->toolCallId);
        self::assertSame(0, $result->orderIndex);
        self::assertSame(['tool_name' => 'web_search', 'content' => [['type' => 'text', 'text' => 'ok']]], $result->result);
        self::assertFalse($result->isError);
        self::assertNull($result->error);
    }

    public function testToolCallResultBuilderSuccessFactory(): void
    {
        $result = ToolCallResultBuilder::success('run-success')
            ->withToolCallId('call-a')
            ->withResult(['tool_name' => 'alpha', 'content' => [['type' => 'text', 'text' => 'A']]])
            ->build();

        self::assertSame('run-success', $result->runId());
        self::assertSame('call-a', $result->toolCallId);
        self::assertFalse($result->isError);
        self::assertNull($result->error);
        self::assertSame('A', $result->result['content'][0]['text']);
    }

    public function testToolCallResultBuilderErrorFactory(): void
    {
        $result = ToolCallResultBuilder::error('run-err', 'Something went wrong')
            ->withToolCallId('call-b')
            ->build();

        self::assertSame('run-err', $result->runId());
        self::assertSame('call-b', $result->toolCallId);
        self::assertTrue($result->isError);
        self::assertSame(['message' => 'Something went wrong'], $result->error);
        self::assertNull($result->result);
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

        self::assertSame('run-override', $result->runId());
        self::assertSame(2, $result->turnNo());
        self::assertSame('custom-step', $result->stepId());
        self::assertSame(3, $result->attempt());
        self::assertSame('ik-result', $result->idempotencyKey());
        self::assertSame('call-c', $result->toolCallId);
        self::assertSame(5, $result->orderIndex);
        self::assertSame(['status' => 'done'], $result->result);
        self::assertFalse($result->isError);
        self::assertNull($result->error);
    }
}
