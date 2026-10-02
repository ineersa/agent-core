<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Runtime\Controller\CommandHandler;

use Ineersa\AgentCore\Contract\Tool\ToolExecutorInterface;
use Ineersa\AgentCore\Domain\Message\ExecuteShellToolCall;
use Ineersa\AgentCore\Domain\Message\ToolCallResult;
use Ineersa\AgentCore\Domain\Tool\ToolCall;
use Ineersa\AgentCore\Domain\Tool\ToolResult;
use Ineersa\AgentCore\Tests\Support\TestMessageBus;
use Ineersa\CodingAgent\Runtime\Controller\CommandHandler\ExecuteShellToolCallWorker;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ExecuteShellToolCallWorker::class)]
final class ExecuteShellToolCallWorkerTest extends TestCase
{
    /**
     * The execution worker has no EventStore dependency. Completion is a durable
     * ToolCallResult routed to run_control, the sole writer of completion and
     * standalone terminal events. Canonical tool_execution_start is owned by
     * ApplyShellCommandHandler before this effect is dispatched.
     */
    public function testStandaloneDispatchesResultToRunControl(): void
    {
        $toolExecutor = $this->createToolExecutor('hello');
        $commandBus = new TestMessageBus();
        $worker = new ExecuteShellToolCallWorker($toolExecutor, $commandBus);
        $worker(new ExecuteShellToolCall(
            runId: 'run-standalone',
            turnNo: 2,
            stepId: 'shell-step',
            attempt: 2,
            toolCallId: 'sh_tc_1',
            commandText: 'echo hello',
            standalone: true,
        ));

        $this->assertCount(1, $commandBus->messages);
        $this->assertInstanceOf(ToolCallResult::class, $commandBus->messages[0]);
        $result = $commandBus->messages[0];
        $this->assertSame('shell-step', $result->stepId());
        $this->assertSame(2, $result->attempt());
        $this->assertSame(hash('sha256', 'run-standalone|sh_tc_1'), $result->idempotencyKey());
        $this->assertSame('sh_tc_1', $result->toolCallId);
        $this->assertSame(['command' => 'echo hello'], $result->result['arguments'] ?? null);
        $this->assertTrue($result->result['standalone'] ?? false);
        $this->assertFalse($result->isError);
    }

    /**
     * Thesis: Non-standalone shell commands (subsequent !cmd during an
     * agent run) must NOT write AgentEnd.  The run is terminated by a
     * separate complete_run command or by the LLM turn's own RunCompleted.
     * Writing AgentEnd here would prematurely terminate the agent run.
     * They also must not dispatch AdvanceRun.
     */
    public function testNonStandaloneDoesNotWriteAgentEnd(): void
    {
        $toolExecutor = $this->createToolExecutor('result', isError: true);
        $commandBus = new TestMessageBus();
        $worker = new ExecuteShellToolCallWorker($toolExecutor, $commandBus);
        $worker(new ExecuteShellToolCall(
            runId: 'run-inline',
            turnNo: 2,
            stepId: 'inline-step',
            attempt: 1,
            toolCallId: 'sh_tc_2',
            commandText: 'echo inline',
            standalone: false,
        ));

        $this->assertCount(1, $commandBus->messages);
        $this->assertInstanceOf(ToolCallResult::class, $commandBus->messages[0]);
        $result = $commandBus->messages[0];
        $this->assertSame('run-inline', $result->runId());
        $this->assertSame('sh_tc_2', $result->toolCallId);
        $this->assertSame([['type' => 'text', 'text' => 'result']], $result->result['content'] ?? null);
        $this->assertFalse($result->result['standalone'] ?? true);
        $this->assertTrue($result->isError);
    }

    /**
     * Creates a stubbed ToolExecutor that returns a fixed result text.
     */
    private function createToolExecutor(string $resultText, bool $isError = false): ToolExecutorInterface
    {
        return new class($resultText, $isError) implements ToolExecutorInterface {
            public function __construct(
                private readonly string $resultText,
                private readonly bool $isError,
            ) {
            }

            public function execute(ToolCall $toolCall): ToolResult
            {
                return new ToolResult(
                    toolCallId: $toolCall->toolCallId,
                    toolName: $toolCall->toolName,
                    content: [['type' => 'text', 'text' => $this->resultText]],
                    isError: $this->isError,
                );
            }
        };
    }
}
