<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Tests\Domain\Message;

use Ineersa\AgentCore\Domain\Message\AdvanceRun;
use Ineersa\AgentCore\Domain\Message\ApplyCommand;
use Ineersa\AgentCore\Domain\Message\ExecuteLlmStep;
use Ineersa\AgentCore\Domain\Message\ExecuteToolCall;
use Ineersa\AgentCore\Domain\Message\LlmStepResult;
use Ineersa\AgentCore\Domain\Message\StartRun;
use Ineersa\AgentCore\Domain\Message\StartRunPayload;
use Ineersa\AgentCore\Domain\Message\ToolCallResult;
use Ineersa\AgentCore\Domain\Run\RunMetadata;
use Ineersa\AgentCore\Tests\Support\Builder\AdvanceRunMessageBuilder;
use Ineersa\AgentCore\Tests\Support\Builder\ToolCallResultBuilder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AgentBusMessageContractTest extends TestCase
{
    #[DataProvider('busMessageProvider')]
    public function testBusMessageAccessors(
        object $message,
        string $class,
        string $expectedRunId,
        int $expectedTurnNo,
        string $expectedStepId,
        int $expectedAttempt,
        string $expectedIdempotencyKey,
    ): void {
        $this->assertInstanceOf($class, $message);
        $this->assertSame($expectedRunId, $message->runId());
        $this->assertSame($expectedTurnNo, $message->turnNo());
        $this->assertSame($expectedStepId, $message->stepId());
        $this->assertSame($expectedAttempt, $message->attempt());
        $this->assertSame($expectedIdempotencyKey, $message->idempotencyKey());
    }

    /**
     * @return array<string, array{0: object, 1: string, 2: string, 3: int, 4: string, 5: int, 6: string}>
     */
    public static function busMessageProvider(): array
    {
        return [
            'StartRun' => [
                new StartRun(
                    runId: 'run-sr', turnNo: 0, stepId: 'sr-step', attempt: 1,
                    idempotencyKey: 'ik-sr',
                    payload: new StartRunPayload(systemPrompt: '', messages: []),
                ),
                StartRun::class, 'run-sr', 0, 'sr-step', 1, 'ik-sr',
            ],
            'AdvanceRun' => [
                new AdvanceRun(
                    runId: 'run-ar', turnNo: 1, stepId: 'ar-step', attempt: 1,
                    idempotencyKey: 'ik-ar',
                    payload: ['reason' => 'continue'],
                ),
                AdvanceRun::class, 'run-ar', 1, 'ar-step', 1, 'ik-ar',
            ],
            'ApplyCommand' => [
                new ApplyCommand(
                    runId: 'run-ac', turnNo: 2, stepId: 'ac-step', attempt: 2,
                    idempotencyKey: 'ik-ac',
                    kind: 'steer',
                    payload: ['input' => 'hi'],
                    options: ['cancel_safe' => true],
                ),
                ApplyCommand::class, 'run-ac', 2, 'ac-step', 2, 'ik-ac',
            ],
            'ExecuteLlmStep' => [
                new ExecuteLlmStep(
                    runId: 'run-llm', turnNo: 1, stepId: 'llm-step', attempt: 1,
                    idempotencyKey: 'ik-llm',
                    contextRef: 'ctx-abc',
                    toolsRef: 'tools-xyz',
                    model: 'test-model',
                ),
                ExecuteLlmStep::class, 'run-llm', 1, 'llm-step', 1, 'ik-llm',
            ],
            'ExecuteToolCall' => [
                new ExecuteToolCall(
                    runId: 'run-etc', turnNo: 2, stepId: 'etc-step', attempt: 1,
                    idempotencyKey: 'ik-etc',
                    toolCallId: 'tc-1', toolName: 'search', args: ['q' => 'test'],
                    orderIndex: 0, toolIdempotencyKey: 'tik-1', mode: 'parallel',
                    timeoutSeconds: 60, maxParallelism: 3,
                    assistantMessage: ['role' => 'assistant', 'content' => 'msg'],
                    argSchema: ['type' => 'object'],
                    toolsRef: 'tools-abc',
                ),
                ExecuteToolCall::class, 'run-etc', 2, 'etc-step', 1, 'ik-etc',
            ],
            'LlmStepResult' => [
                new LlmStepResult(
                    runId: 'run-lsr', turnNo: 1, stepId: 'lsr-step', attempt: 2,
                    idempotencyKey: 'ik-lsr',
                    assistantMessage: null,
                    usage: ['prompt_tokens' => 50],
                    stopReason: 'end_turn',
                    error: null,
                    toolsRef: 'tools-def',
                ),
                LlmStepResult::class, 'run-lsr', 1, 'lsr-step', 2, 'ik-lsr',
            ],
            'ToolCallResult' => [
                new ToolCallResult(
                    runId: 'run-tcr', turnNo: 3, stepId: 'tcr-step', attempt: 1,
                    idempotencyKey: 'ik-tcr',
                    toolCallId: 'tc-2', orderIndex: 1,
                    result: ['status' => 'ok'],
                    isError: false, error: null,
                ),
                ToolCallResult::class, 'run-tcr', 3, 'tcr-step', 1, 'ik-tcr',
            ],
        ];
    }

    /* ─── Class-specific payload fields ───
     *
     * The data-driven busMessageProvider above tests that all 7 concrete message
     * classes implement the 5 AbstractAgentBusMessage accessors correctly. The
     * individual tests below verify class-specific public fields (payload, kind,
     * options, refs, etc.) that are unique to each concrete class, not covered
     * by the shared provider.
     */

    public function testStartRunPreservesStartRunPayload(): void
    {
        $message = new StartRun(
            runId: 'run', turnNo: 0, stepId: 's', attempt: 1,
            idempotencyKey: 'ik',
            payload: new StartRunPayload(
                systemPrompt: 'You are helpful',
                messages: [],
                metadata: new RunMetadata(model: 'gpt-4'),
            ),
        );

        $this->assertSame('You are helpful', $message->payload->systemPrompt);
        $this->assertSame([], $message->payload->messages);
        $this->assertSame('gpt-4', $message->payload->metadata?->model);
    }

    public function testAdvanceRunPreservesPayload(): void
    {
        $message = AdvanceRunMessageBuilder::create('run-adv')
            ->withTurnNo(3)
            ->withPayload(['reason' => 'continue', 'turn' => 3])
            ->withIdempotencyKey('ik-adv')
            ->build();

        $this->assertSame(['reason' => 'continue', 'turn' => 3], $message->payload);
        $this->assertSame('run-adv', $message->runId());
        $this->assertSame(3, $message->turnNo());
    }

    public function testApplyCommandPreservesKindPayloadAndOptions(): void
    {
        $message = new ApplyCommand(
            runId: 'run', turnNo: 1, stepId: 's', attempt: 1,
            idempotencyKey: 'ik',
            kind: 'human_response',
            payload: ['response' => 'proceed'],
            options: ['cancel_safe' => false],
        );

        $this->assertSame('human_response', $message->kind);
        $this->assertSame(['response' => 'proceed'], $message->payload);
        $this->assertSame(['cancel_safe' => false], $message->options);
    }

    public function testExecuteLlmStepPreservesRefsAndModel(): void
    {
        $message = new ExecuteLlmStep(
            runId: 'run', turnNo: 1, stepId: 's', attempt: 1,
            idempotencyKey: 'ik',
            contextRef: 'ctx-main',
            toolsRef: 'tools-v2',
            model: 'deepseek/deepseek-v4-flash',
        );

        $this->assertSame('ctx-main', $message->contextRef);
        $this->assertSame('tools-v2', $message->toolsRef);
        $this->assertSame('deepseek/deepseek-v4-flash', $message->model);
    }

    public function testExecuteToolCallPreservesAllFields(): void
    {
        $message = new ExecuteToolCall(
            runId: 'run-etc', turnNo: 2, stepId: 'etc-step', attempt: 1,
            idempotencyKey: 'ik-etc',
            toolCallId: 'tc-1', toolName: 'web_search',
            args: ['query' => 'php 8.4'], orderIndex: 0,
            toolIdempotencyKey: 'tik-xyz', mode: 'parallel',
            timeoutSeconds: 30, maxParallelism: 2,
            assistantMessage: ['role' => 'assistant', 'content' => [['type' => 'text', 'text' => 'checking']]],
            argSchema: ['type' => 'object', 'properties' => ['query' => ['type' => 'string']]],
            toolsRef: 'tools-v3',
        );

        $this->assertSame('tc-1', $message->toolCallId);
        $this->assertSame('web_search', $message->toolName);
        $this->assertSame(['query' => 'php 8.4'], $message->args);
        $this->assertSame(0, $message->orderIndex);
        $this->assertSame('tik-xyz', $message->toolIdempotencyKey);
        $this->assertSame('parallel', $message->mode);
        $this->assertSame(30, $message->timeoutSeconds);
        $this->assertSame(2, $message->maxParallelism);
        $this->assertSame(['role' => 'assistant', 'content' => [['type' => 'text', 'text' => 'checking']]], $message->assistantMessage);
        $this->assertSame(['type' => 'object', 'properties' => ['query' => ['type' => 'string']]], $message->argSchema);
        $this->assertSame('tools-v3', $message->toolsRef);
    }

    public function testLlmStepResultPreservesAllFields(): void
    {
        $message = new LlmStepResult(
            runId: 'run-lsr', turnNo: 1, stepId: 'lsr-step', attempt: 2,
            idempotencyKey: 'ik-lsr',
            assistantMessage: null,
            usage: ['prompt_tokens' => 100, 'completion_tokens' => 50],
            stopReason: 'max_tokens',
            error: null,
            toolsRef: 'tools-def',
        );

        $this->assertNull($message->assistantMessage);
        $this->assertSame(['prompt_tokens' => 100, 'completion_tokens' => 50], $message->usage);
        $this->assertSame('max_tokens', $message->stopReason);
        $this->assertNull($message->error);
        $this->assertSame('tools-def', $message->toolsRef);
    }

    public function testToolCallResultPreservesAllFields(): void
    {
        $message = ToolCallResultBuilder::success('run-tcr')
            ->withTurnNo(2)
            ->withToolCallId('tc-abc')
            ->withOrderIndex(5)
            ->withResult(['status' => 'completed'])
            ->withIdempotencyKey('ik-tcr')
            ->build();

        $this->assertSame('run-tcr', $message->runId());
        $this->assertSame(2, $message->turnNo());
        $this->assertSame('tc-abc', $message->toolCallId);
        $this->assertSame(5, $message->orderIndex);
        $this->assertSame(['status' => 'completed'], $message->result);
        $this->assertFalse($message->isError);
        $this->assertNull($message->error);
    }
}
