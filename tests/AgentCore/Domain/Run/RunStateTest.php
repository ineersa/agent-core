<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Tests\Domain\Run;

use Ineersa\AgentCore\Domain\Message\AgentMessage;
use Ineersa\AgentCore\Domain\Message\LlmStepResult;
use Ineersa\AgentCore\Domain\Run\DTO\CurrentOperationDTO;
use Ineersa\AgentCore\Domain\Run\DTO\CurrentToolCallDTO;
use Ineersa\AgentCore\Domain\Run\HumanInputContinuationKindEnum;
use Ineersa\AgentCore\Domain\Run\PendingHumanInputRequestDTO;
use Ineersa\AgentCore\Domain\Run\RunOperationalToolCallStatusEnum;
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

    public function testStandaloneShellToolCallIdIsPrefixedSha256OfIdempotencyKey(): void
    {
        $this->assertSame('sh_8664bb0d8332af6980b61db25a21d8d6788eeca16284c9f2240c2f1fcdc2fb1a', RunState::standaloneShellToolCallId('shell-key'));
    }

    public function testWithReplacesOnlyOverriddenFields(): void
    {
        $state = new RunState(
            runId: 'run-with',
            status: RunStatus::Running,
            version: 3,
            turnNo: 2,
            lastSeq: 9,
            isStreaming: true,
            pendingToolCalls: ['call-1' => false],
            pendingShellToolCalls: ['sh_x' => true],
            currentToolCalls: [new CurrentToolCallDTO('batch', 'call-1', 0, RunOperationalToolCallStatusEnum::Running, 1)],
            errorMessage: 'error',
            messages: [new AgentMessage('user', [['type' => 'text', 'text' => 'hi']])],
            activeStepId: 'step',
            currentOperation: new CurrentOperationDTO(2, 'step', 1, 'key'),
            lastAppliedAdvanceKey: 'advance',
            lastAppliedCompactionKey: 'compaction',
            pendingHumanInputRequests: [new PendingHumanInputRequestDTO('q', HumanInputContinuationKindEnum::ToolCall, [])],
            model: 'provider/model',
            parentRunId: 'parent',
        );

        $this->assertEquals($state, $state->with());

        $next = $state->with(['status' => RunStatus::Completed, 'version' => 4, 'errorMessage' => null, 'currentOperation' => null]);

        $expected = get_object_vars($state);
        $expected['status'] = RunStatus::Completed;
        $expected['version'] = 4;
        $expected['errorMessage'] = null;
        $expected['currentOperation'] = null;
        $this->assertEquals($expected, get_object_vars($next));
        $this->assertSame(RunStatus::Running, $state->status);
    }
}
