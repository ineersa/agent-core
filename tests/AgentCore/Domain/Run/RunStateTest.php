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

        self::assertSame('run-test-1', $state->runId);
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

        self::assertSame('run-x', $state->runId);
        self::assertSame(RunStatus::Running, $state->status);
        self::assertSame(3, $state->version);
        self::assertSame(2, $state->turnNo);
        self::assertSame(7, $state->lastSeq);
        self::assertTrue($state->isStreaming);
        self::assertSame(['chunk' => 'data'], $state->streamingMessage);
        self::assertSame(['call-1' => false], $state->pendingToolCalls);
        self::assertSame('something broke', $state->errorMessage);
        self::assertCount(1, $state->messages);
        self::assertSame('hello', $state->messages[0]->content[0]['text']);
        self::assertSame('step-42', $state->activeStepId);
        self::assertTrue($state->retryableFailure);
    }

    /**
     * PHP backed enums guarantee from() and value round-trip intrinsically.
     * One test looping over ::cases() is sufficient coverage.
     */
    public function testRunStatusRoundTrip(): void
    {
        foreach (RunStatus::cases() as $status) {
            self::assertSame($status, RunStatus::from($status->value));
        }

        $this->expectException(\ValueError::class);
        RunStatus::from('invalid_status');
    }
}
