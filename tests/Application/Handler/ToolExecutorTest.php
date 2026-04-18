<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Tests\Application\Handler;

use Ineersa\AgentCore\Application\Handler\ToolExecutionResultStore;
use Ineersa\AgentCore\Application\Handler\ToolExecutor;
use Ineersa\AgentCore\Contract\Hook\AfterToolCallHookInterface;
use Ineersa\AgentCore\Contract\Hook\BeforeToolCallHookInterface;
use Ineersa\AgentCore\Contract\Hook\CancellationTokenInterface;
use Ineersa\AgentCore\Domain\Tool\AfterToolCallContext;
use Ineersa\AgentCore\Domain\Tool\AfterToolCallResult;
use Ineersa\AgentCore\Domain\Tool\BeforeToolCallContext;
use Ineersa\AgentCore\Domain\Tool\BeforeToolCallResult;
use Ineersa\AgentCore\Domain\Tool\ToolCall;
use PHPUnit\Framework\TestCase;

final class ToolExecutorTest extends TestCase
{
    public function testInterruptModeProducesInterruptPayload(): void
    {
        $executor = new ToolExecutor('interrupt', 30, 2);

        $result = $executor->execute(new ToolCall(
            toolCallId: 'tool-call-1',
            toolName: 'ask_user',
            arguments: [
                'question_id' => 'q-1',
                'prompt' => 'Approve deployment?',
                'schema' => ['type' => 'boolean'],
            ],
            orderIndex: 0,
            runId: 'run-stage-06',
            mode: \Ineersa\AgentCore\Domain\Tool\ToolExecutionMode::Interrupt,
        ));

        self::assertFalse($result->isError);
        self::assertIsArray($result->details);
        self::assertSame('interrupt', $result->details['kind']);
        self::assertSame('q-1', $result->details['question_id']);
        self::assertSame('Approve deployment?', $result->details['prompt']);
    }

    public function testRunScopedDedupeReusesTerminalToolResult(): void
    {
        $this->ensureSymfonyToolCallStub();

        $toolbox = new CountingToolbox();
        $executor = new ToolExecutor(
            defaultMode: 'parallel',
            defaultTimeoutSeconds: 30,
            maxParallelism: 4,
            overrides: [],
            toolbox: $toolbox,
            resultStore: new ToolExecutionResultStore(),
        );

        $first = $executor->execute(new ToolCall(
            toolCallId: 'call-1',
            toolName: 'web_search',
            arguments: ['query' => 'symfony'],
            orderIndex: 0,
            runId: 'run-stage-06',
        ));

        $second = $executor->execute(new ToolCall(
            toolCallId: 'call-1',
            toolName: 'web_search',
            arguments: ['query' => 'symfony'],
            orderIndex: 0,
            runId: 'run-stage-06',
        ));

        self::assertSame(1, $toolbox->executions);
        self::assertFalse($first->isError);
        self::assertFalse($second->isError);
        self::assertSame('run_tool_call_dedupe', $second->details['idempotency_reuse_reason']);
    }

    public function testToolIdempotencyKeyReusePreventsDuplicateExternalExecution(): void
    {
        $this->ensureSymfonyToolCallStub();

        $toolbox = new CountingToolbox();
        $executor = new ToolExecutor(
            defaultMode: 'parallel',
            defaultTimeoutSeconds: 30,
            maxParallelism: 4,
            overrides: [],
            toolbox: $toolbox,
            resultStore: new ToolExecutionResultStore(),
        );

        $executor->execute(new ToolCall(
            toolCallId: 'call-1',
            toolName: 'web_search',
            arguments: ['query' => 'symfony'],
            orderIndex: 0,
            runId: 'run-stage-06',
            toolIdempotencyKey: 'idem-1',
        ));

        $second = $executor->execute(new ToolCall(
            toolCallId: 'call-2',
            toolName: 'web_search',
            arguments: ['query' => 'symfony'],
            orderIndex: 1,
            runId: 'run-stage-06',
            toolIdempotencyKey: 'idem-1',
        ));

        self::assertSame(1, $toolbox->executions);
        self::assertSame('call-2', $second->toolCallId);
        self::assertSame('tool_idempotency_reuse', $second->details['idempotency_reuse_reason']);
    }

    public function testSchemaValidationAndAfterHookOverrideApplyOnBlockedTools(): void
    {
        $before = new class implements BeforeToolCallHookInterface {
            public function beforeToolCall(BeforeToolCallContext $context, ?CancellationTokenInterface $cancelToken = null): ?BeforeToolCallResult
            {
                return BeforeToolCallResult::blocked('Blocked by policy.');
            }
        };

        $after = new class implements AfterToolCallHookInterface {
            public function afterToolCall(AfterToolCallContext $context, ?CancellationTokenInterface $cancelToken = null): ?AfterToolCallResult
            {
                return AfterToolCallResult::withDetails(['after' => 'override'])->withIsError(false);
            }
        };

        $executor = new ToolExecutor(
            defaultMode: 'sequential',
            defaultTimeoutSeconds: 30,
            maxParallelism: 1,
            overrides: [],
            beforeToolCallHooks: [$before],
            afterToolCallHooks: [$after],
        );

        $result = $executor->execute(new ToolCall(
            toolCallId: 'blocked-1',
            toolName: 'web_search',
            arguments: [],
            orderIndex: 0,
            runId: 'run-stage-06',
            context: [
                'arg_schema' => [
                    'type' => 'object',
                    'required' => ['query'],
                    'properties' => [
                        'query' => ['type' => 'string'],
                    ],
                ],
            ],
        ));

        self::assertFalse($result->isError, 'afterToolCall override should be able to change error status.');
        self::assertSame('override', $result->details['after']);
    }

    private function ensureSymfonyToolCallStub(): void
    {
        if (class_exists('Symfony\\AI\\Platform\\Result\\ToolCall')) {
            return;
        }

        eval('namespace Symfony\\AI\\Platform\\Result; final class ToolCall { public function __construct(public string $id, public string $name, public array $arguments = []) {} }');
    }
}

final class CountingToolbox
{
    public int $executions = 0;

    public function execute(object $toolCall): object
    {
        ++$this->executions;

        return new class {
            public function getResult(): mixed
            {
                return ['status' => 'ok'];
            }

            public function getSources(): array
            {
                return ['source'];
            }
        };
    }
}
