<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Tests\Application\Handler;

use Ineersa\AgentCore\Application\Handler\ToolExecutionResultStore;
use Ineersa\AgentCore\Application\Handler\ToolExecutor;
use Ineersa\AgentCore\Domain\Tool\ToolCall;
use Ineersa\AgentCore\Domain\Tool\ToolExecutionMode;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Agent\Toolbox\Attribute\AsTool;
use Symfony\AI\Agent\Toolbox\Event\ToolCallRequested;
use Symfony\AI\Agent\Toolbox\Toolbox;
use Symfony\AI\Agent\Toolbox\ToolboxInterface;
use Symfony\AI\Agent\Toolbox\ToolResult as SymfonyToolResult;
use Symfony\AI\Platform\Result\ToolCall as SymfonyToolCall;
use Symfony\Component\EventDispatcher\EventDispatcher;

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
            mode: ToolExecutionMode::Interrupt,
        ));

        self::assertFalse($result->isError);
        self::assertIsArray($result->details);
        self::assertSame('interrupt', $result->details['kind']);
        self::assertSame('q-1', $result->details['question_id']);
        self::assertSame('Approve deployment?', $result->details['prompt']);
    }

    public function testToolExecutionIsUnavailableWithoutToolbox(): void
    {
        $executor = new ToolExecutor('parallel', 30, 2);

        $result = $executor->execute(new ToolCall(
            toolCallId: 'call-1',
            toolName: 'web_search',
            arguments: ['query' => 'symfony'],
            orderIndex: 0,
        ));

        self::assertTrue($result->isError);
        self::assertStringContainsString('execution is unavailable', $result->content[0]['text']);
    }

    public function testRunScopedDedupeReusesTerminalToolResult(): void
    {
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

    public function testSymfonyToolboxRequestedEventCanDenyExecution(): void
    {
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(ToolCallRequested::class, static function (ToolCallRequested $event): void {
            $event->deny('Blocked by policy listener.');
        });

        $toolbox = new Toolbox([new SymfonySearchTool()], eventDispatcher: $dispatcher);

        $executor = new ToolExecutor(
            defaultMode: 'parallel',
            defaultTimeoutSeconds: 30,
            maxParallelism: 4,
            overrides: [],
            toolbox: $toolbox,
            resultStore: new ToolExecutionResultStore(),
        );

        $result = $executor->execute(new ToolCall(
            toolCallId: 'call-3',
            toolName: 'web_search',
            arguments: ['query' => 'agent core'],
            orderIndex: 0,
        ));

        self::assertFalse($result->isError);
        self::assertSame('Blocked by policy listener.', $result->details['raw_result']);
        self::assertSame('Blocked by policy listener.', $result->content[0]['text']);
    }
}

final class CountingToolbox implements ToolboxInterface
{
    public int $executions = 0;

    public function getTools(): array
    {
        return [];
    }

    public function execute(SymfonyToolCall $toolCall): SymfonyToolResult
    {
        ++$this->executions;

        return new SymfonyToolResult($toolCall, ['status' => 'ok']);
    }
}

#[AsTool(name: 'web_search', description: 'Searches the web for relevant snippets.')]
final class SymfonySearchTool
{
    /**
     * @return array{query: string, status: string}
     */
    public function __invoke(string $query): array
    {
        return [
            'query' => $query,
            'status' => 'ok',
        ];
    }
}
