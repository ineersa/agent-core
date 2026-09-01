<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Tests\Domain\Run;

use Ineersa\AgentCore\Domain\Message\LlmStepResult;
use Ineersa\AgentCore\Domain\Run\CurrentOperationDTO;
use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RunStateTest extends TestCase
{
    public static function llmAdmissionCases(): iterable
    {
        $operation = new CurrentOperationDTO(1, 'llm-step', 1, 'llm-key');

        yield 'running exact operation' => [RunStatus::Running, $operation, [], $operation, true];
        yield 'cancelling exact operation' => [RunStatus::Cancelling, $operation, [], $operation, true];
        yield 'terminal status' => [RunStatus::Completed, $operation, [], $operation, false];
        yield 'missing operation' => [RunStatus::Running, null, [], $operation, false];
        yield 'mismatched operation' => [RunStatus::Running, $operation, [], new CurrentOperationDTO(1, 'other', 1, 'llm-key'), false];
        yield 'standalone shell operation' => [RunStatus::Running, $operation, ['sh_'.hash('sha256', 'llm-key') => true], $operation, false];
        yield 'attached shell alongside LLM operation' => [RunStatus::Running, $operation, ['sh_'.hash('sha256', 'shell-key') => true], $operation, true];
    }

    #[DataProvider('llmAdmissionCases')]
    public function testLlmResultAdmissionPartitions(
        RunStatus $status,
        ?CurrentOperationDTO $currentOperation,
        array $pendingShellToolCalls,
        CurrentOperationDTO $messageOperation,
        bool $expected,
    ): void {
        $state = new RunState(
            runId: 'run-llm-admission',
            status: $status,
            currentOperation: $currentOperation,
            pendingShellToolCalls: $pendingShellToolCalls,
        );
        $message = new LlmStepResult(
            'run-llm-admission',
            $messageOperation->turnNo,
            $messageOperation->stepId,
            $messageOperation->attempt,
            $messageOperation->idempotencyKey,
            null,
            [],
            null,
            null,
        );

        $this->assertSame($expected, $state->canAcceptLlmResult($message));
    }

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
    }
}
