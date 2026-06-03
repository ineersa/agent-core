<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Tests\Domain\Run;

use Ineersa\AgentCore\Domain\Message\AgentMessage;
use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\AgentCore\Tests\Support\Builder\RunStateBuilder;
use PHPUnit\Framework\Attributes\DataProvider;
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

    #[DataProvider('runStatusProvider')]
    public function testRunStatusEnumValues(string $expectedValue, RunStatus $status): void
    {
        self::assertSame($expectedValue, $status->value);
    }

    /**
     * @return array<string, array{0: string, 1: RunStatus}>
     */
    public static function runStatusProvider(): array
    {
        return [
            'queued' => ['queued', RunStatus::Queued],
            'running' => ['running', RunStatus::Running],
            'waiting_human' => ['waiting_human', RunStatus::WaitingHuman],
            'cancelling' => ['cancelling', RunStatus::Cancelling],
            'completed' => ['completed', RunStatus::Completed],
            'failed' => ['failed', RunStatus::Failed],
            'cancelled' => ['cancelled', RunStatus::Cancelled],
        ];
    }

    #[DataProvider('runStatusFromProvider')]
    public function testRunStatusFromReturnsCorrectCase(string $value, RunStatus $expected): void
    {
        self::assertSame($expected, RunStatus::from($value));
    }

    /**
     * @return array<string, array{0: string, 1: RunStatus}>
     */
    public static function runStatusFromProvider(): array
    {
        return [
            'queued' => ['queued', RunStatus::Queued],
            'running' => ['running', RunStatus::Running],
            'waiting_human' => ['waiting_human', RunStatus::WaitingHuman],
            'cancelling' => ['cancelling', RunStatus::Cancelling],
            'completed' => ['completed', RunStatus::Completed],
            'failed' => ['failed', RunStatus::Failed],
            'cancelled' => ['cancelled', RunStatus::Cancelled],
        ];
    }

    public function testRunStatusFromInvalidStringThrowsValueError(): void
    {
        $this->expectException(\ValueError::class);

        RunStatus::from('invalid_status');
    }
}
