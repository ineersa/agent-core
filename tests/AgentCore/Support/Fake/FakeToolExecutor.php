<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Tests\Support\Fake;

use Ineersa\AgentCore\Contract\Tool\ToolExecutorInterface;
use Ineersa\AgentCore\Domain\Tool\ToolCall;
use Ineersa\AgentCore\Domain\Tool\ToolResult;

final class FakeToolExecutor implements ToolExecutorInterface
{
    /** @var array<string, callable(ToolCall): ToolResult> */
    private array $handlersByTool = [];

    /** @var list<ToolCall> */
    public array $calls = [];

    /**
     * @param array<string, callable(ToolCall): ToolResult> $handlersByTool
     */
    public function __construct(array $handlersByTool = [])
    {
        $this->handlersByTool = $handlersByTool;
    }

    public function on(string $toolName, callable $handler): void
    {
        $this->handlersByTool[$toolName] = $handler;
    }

    public function execute(ToolCall $toolCall): ToolResult
    {
        $this->calls[] = $toolCall;

        $handler = $this->handlersByTool[$toolCall->toolName] ?? null;
        if (null !== $handler) {
            return $handler($toolCall);
        }

        return new ToolResult(
            toolCallId: $toolCall->toolCallId,
            toolName: $toolCall->toolName,
            content: [[
                'type' => 'text',
                'text' => 'fake-tool-default',
            ]],
            details: [
                'arguments' => $toolCall->arguments,
            ],
            isError: false,
        );
    }
}
