<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Infrastructure\SymfonyAi;

use Ineersa\AgentCore\Application\Handler\ToolExecutor;
use Ineersa\AgentCore\Contract\Hook\AfterToolCallHookInterface;
use Ineersa\AgentCore\Contract\Hook\BeforeToolCallHookInterface;
use Ineersa\AgentCore\Contract\Hook\NullCancellationToken;
use Ineersa\AgentCore\Contract\Tool\ToolExecutorInterface;
use Ineersa\AgentCore\Domain\Message\AgentMessage;
use Ineersa\AgentCore\Domain\Tool\AfterToolCallContext;
use Ineersa\AgentCore\Domain\Tool\BeforeToolCallContext;
use Ineersa\AgentCore\Domain\Tool\ToolCall;
use Ineersa\AgentCore\Domain\Tool\ToolResult;

final readonly class SymfonyToolExecutorAdapter implements ToolExecutorInterface
{
    /**
     * @param iterable<BeforeToolCallHookInterface> $beforeToolCallHooks
     * @param iterable<AfterToolCallHookInterface>  $afterToolCallHooks
     */
    public function __construct(
        private ToolExecutor $fallbackExecutor,
        private ?object $toolbox = null,
        private iterable $beforeToolCallHooks = [],
        private iterable $afterToolCallHooks = [],
    ) {
    }

    public function execute(ToolCall $toolCall): ToolResult
    {
        if (!$this->canUseSymfonyToolbox()) {
            return $this->fallbackExecutor->execute($toolCall);
        }

        $cancelToken = new NullCancellationToken();
        $assistantMessage = new AgentMessage('assistant', []);

        foreach ($this->beforeToolCallHooks as $hook) {
            $result = $hook->beforeToolCall(new BeforeToolCallContext(
                assistantMessage: $assistantMessage,
                toolCall: $toolCall,
                args: $toolCall->arguments,
                context: [
                    'tool_name' => $toolCall->toolName,
                    'tool_call_id' => $toolCall->toolCallId,
                ],
            ), $cancelToken);

            if ($result?->block ?? false) {
                return new ToolResult(
                    toolCallId: $toolCall->toolCallId,
                    toolName: $toolCall->toolName,
                    content: [[
                        'type' => 'text',
                        'text' => $result->reason ?? \sprintf('Execution of tool "%s" was blocked by before_tool_call hook.', $toolCall->toolName),
                    ]],
                    details: ['blocked' => true],
                    isError: true,
                );
            }
        }

        try {
            $symfonyToolCall = $this->toSymfonyToolCall($toolCall);
            $toolboxResult = $this->toolbox->execute($symfonyToolCall);

            $rawResult = method_exists($toolboxResult, 'getResult')
                ? $toolboxResult->getResult()
                : null;

            $details = [
                'raw_result' => $rawResult,
            ];

            if (method_exists($toolboxResult, 'getSources')) {
                $details['sources'] = $toolboxResult->getSources();
            }

            $domainResult = new ToolResult(
                toolCallId: $toolCall->toolCallId,
                toolName: $toolCall->toolName,
                content: [[
                    'type' => 'text',
                    'text' => $this->stringify($rawResult),
                ]],
                details: $details,
                isError: false,
            );
        } catch (\Throwable $exception) {
            $domainResult = new ToolResult(
                toolCallId: $toolCall->toolCallId,
                toolName: $toolCall->toolName,
                content: [[
                    'type' => 'text',
                    'text' => $exception->getMessage(),
                ]],
                details: [
                    'error_type' => $exception::class,
                ],
                isError: true,
            );
        }

        foreach ($this->afterToolCallHooks as $hook) {
            $after = $hook->afterToolCall(new AfterToolCallContext(
                assistantMessage: $assistantMessage,
                toolCall: $toolCall,
                args: $toolCall->arguments,
                result: $domainResult,
                isError: $domainResult->isError,
                context: [
                    'tool_name' => $toolCall->toolName,
                    'tool_call_id' => $toolCall->toolCallId,
                ],
            ), $cancelToken);

            if (null === $after) {
                continue;
            }

            $domainResult = new ToolResult(
                toolCallId: $domainResult->toolCallId,
                toolName: $domainResult->toolName,
                content: $after->hasContentOverride ? $after->content : $domainResult->content,
                details: $after->hasDetailsOverride ? $after->details : $domainResult->details,
                isError: $after->isError ?? $domainResult->isError,
            );
        }

        return $domainResult;
    }

    /**
     * @return array<string, mixed>
     */
    public function toToolCallMessagePayload(ToolCall $toolCall, ToolResult $result): array
    {
        $payload = [
            'is_error' => $result->isError,
            'content' => $result->content,
            'details' => $result->details,
        ];

        $content = json_encode($payload);
        if (false === $content) {
            $content = '{}';
        }

        return [
            'role' => 'tool',
            'tool_call' => [
                'id' => $toolCall->toolCallId,
                'name' => $toolCall->toolName,
                'arguments' => $toolCall->arguments,
            ],
            'content' => $content,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toProgressUpdate(string $toolCallId, string $toolName, string $message, int $progress): array
    {
        return [
            'type' => 'tool_execution_update',
            'tool_call_id' => $toolCallId,
            'tool_name' => $toolName,
            'message' => $message,
            'progress' => max(0, min(100, $progress)),
        ];
    }

    private function canUseSymfonyToolbox(): bool
    {
        return null !== $this->toolbox
            && method_exists($this->toolbox, 'execute')
            && class_exists('Symfony\\AI\\Platform\\Result\\ToolCall');
    }

    private function toSymfonyToolCall(ToolCall $toolCall): object
    {
        /** @var class-string $toolCallClass */
        $toolCallClass = 'Symfony\\AI\\Platform\\Result\\ToolCall';

        return new $toolCallClass(
            $toolCall->toolCallId,
            $toolCall->toolName,
            $toolCall->arguments,
        );
    }

    private function stringify(mixed $value): string
    {
        if (null === $value) {
            return '';
        }

        if (\is_string($value)) {
            return $value;
        }

        if (\is_scalar($value)) {
            return (string) $value;
        }

        if ($value instanceof \Stringable) {
            return (string) $value;
        }

        $encoded = json_encode($value);

        return false === $encoded ? '{}' : $encoded;
    }
}
