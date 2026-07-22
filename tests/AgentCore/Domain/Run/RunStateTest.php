<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Tests\Domain\Run;

use Ineersa\AgentCore\Domain\Message\AgentMessage;
use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\AgentCore\Tests\Support\Builder\RunStateBuilder;
use PHPUnit\Framework\TestCase;

final class RunStateTest extends TestCase
{
    public function testQueuedFactoryCreatesQueuedStateWithDefaults(): void
    {
        $state = RunState::queued('run-test-1');

        $this->assertSame('run-test-1', $state->runId);
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

    public function testConstructorWithRunningBuilderPreservesAllFields(): void
    {
        $message = new AgentMessage(role: 'assistant', content: [['type' => 'text', 'text' => 'hello']]);

        $state = RunStateBuilder::running('run-x')
            ->withVersion(3)
            ->withTurnNo(2)
            ->withLastSeq(7)
            ->withIsStreaming(true)
            ->withStreamingMessage(['chunk' => 'data'])
            ->withPendingToolCalls(['call-1' => false])
            ->withErrorMessage('something broke')
            ->withMessages([$message])
            ->withActiveStepId('step-42')
            ->withRetryableFailure(true)
            ->build();

        $this->assertSame('run-x', $state->runId);
        $this->assertSame(RunStatus::Running, $state->status);
        $this->assertSame(3, $state->version);
        $this->assertSame(2, $state->turnNo);
        $this->assertSame(7, $state->lastSeq);
        $this->assertTrue($state->isStreaming);
        $this->assertSame(['chunk' => 'data'], $state->streamingMessage);
        $this->assertSame(['call-1' => false], $state->pendingToolCalls);
        $this->assertSame('something broke', $state->errorMessage);
        $this->assertCount(1, $state->messages);
        $this->assertSame('hello', $state->messages[0]->content[0]['text']);
        $this->assertSame('step-42', $state->activeStepId);
        $this->assertTrue($state->retryableFailure);
    }

    /**
     * PHP backed enums guarantee from() and value round-trip intrinsically.
     * One test looping over ::cases() is sufficient coverage.
     */
    public function testRunStatusRoundTrip(): void
    {
        foreach (RunStatus::cases() as $status) {
            $this->assertSame($status, RunStatus::from($status->value));
        }

        $this->expectException(\ValueError::class);
        RunStatus::from('invalid_status');
    }
}
