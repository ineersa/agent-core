<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Runtime\Controller\CommandHandler;

use Ineersa\AgentCore\Application\Handler\ToolExecutionResultStore;
use Ineersa\AgentCore\Contract\Tool\ToolExecutorInterface;
use Ineersa\AgentCore\Domain\Message\ExecuteShellToolCall;
use Ineersa\AgentCore\Domain\Tool\ToolCall;
use Ineersa\AgentCore\Domain\Tool\ToolResult;
use Ineersa\AgentCore\Tests\Support\TestLogger;
use Ineersa\AgentCore\Tests\Support\TestMessageBus;
use Ineersa\CodingAgent\Runtime\Controller\CommandHandler\ExecuteShellToolCallWorker;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

#[CoversClass(ExecuteShellToolCallWorker::class)]
final class ExecuteShellToolCallWorkerTest extends TestCase
{
    public function testSuccessfulDeliveryReleasesRememberedResultsAcrossRepeatedCalls(): void
    {
        $store = new ToolExecutionResultStore();
        $executor = $this->rememberingExecutor($store, 'ok');
        $worker = new ExecuteShellToolCallWorker(new TestMessageBus(), $executor, new TestLogger(), $store);

        for ($i = 0; $i < 5; ++$i) {
            $toolCallId = 'shell-success-'.$i;
            $worker(new ExecuteShellToolCall(
                runId: 'run-shell-success',
                turnNo: 1,
                stepId: 'shell-step',
                attempt: 1,
                toolCallId: $toolCallId,
                commandText: 'true',
                standalone: true,
            ));
            $this->assertNull($store->findByRunToolCall('run-shell-success', $toolCallId));
        }
    }

    public function testFailedDeliveryKeepsRememberedResult(): void
    {
        $store = new ToolExecutionResultStore();
        $executor = $this->rememberingExecutor($store, 'kept-on-failure');
        $bus = new class implements MessageBusInterface {
            public function dispatch(object $message, array $stamps = []): Envelope
            {
                throw new \RuntimeException('dispatch failed');
            }
        };
        $logger = new TestLogger();
        $worker = new ExecuteShellToolCallWorker($bus, $executor, $logger, $store);

        $result = $worker(new ExecuteShellToolCall(
            runId: 'run-shell-fail',
            turnNo: 1,
            stepId: 'shell-step',
            attempt: 1,
            toolCallId: 'shell-fail-1',
            commandText: 'true',
            standalone: true,
        ));

        $this->assertSame('shell-fail-1', $result->toolCallId);
        $this->assertNotNull($store->findByRunToolCall('run-shell-fail', 'shell-fail-1'));
        $this->assertSame('runtime.result_send_failed', $logger->records[array_key_last($logger->records)]['message']);
    }

    private function rememberingExecutor(ToolExecutionResultStore $store, string $text): ToolExecutorInterface
    {
        return new class($store, $text) implements ToolExecutorInterface {
            public function __construct(
                private ToolExecutionResultStore $store,
                private string $text,
            ) {
            }

            public function execute(ToolCall $toolCall): ToolResult
            {
                $result = new ToolResult(
                    toolCallId: $toolCall->toolCallId,
                    toolName: 'bash',
                    content: [['type' => 'text', 'text' => $this->text]],
                    details: [],
                    isError: false,
                );
                $this->store->remember(
                    $toolCall->runId ?? 'missing-run',
                    $toolCall->toolCallId,
                    'bash',
                    null,
                    $result,
                );

                return $result;
            }
        };
    }
}
