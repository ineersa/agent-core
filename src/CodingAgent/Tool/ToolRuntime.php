<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tool;

use Ineersa\AgentCore\Application\Tool\StackToolExecutionContextAccessor;

/**
 * Shared runtime helper for tool authors providing cancellable execution checkpoints.
 *
 * {@see run()} checks cancellation before and after the callback and throws a clear
 * RuntimeException if cancelled so ToolExecutor converts it to a structured error.
 *
 * Designed for use inside invokable tool handler implementations.
 * The ambient ToolContext (populated by ToolExecutor) provides the cancellation token.
 */
final readonly class ToolRuntime
{
    public function __construct(
        private StackToolExecutionContextAccessor $contextAccessor,
    ) {
    }

    /**
     * Execute a callback with cancellation checkpoints.
     *
     * Checks the ambient ToolContext cancellation token both before and after
     * the callback. If cancelled before execution, throws immediately. If
     * cancelled during execution (detected after the callback), throws with
     * a stale-result message.
     *
     * @param callable(): mixed $callback the tool logic to execute
     *
     * @return mixed the callback return value
     *
     * @throws \RuntimeException when cancellation is detected before or after
     *                           the callback. ToolExecutor catches this and
     *                           returns a structured error result.
     */
    public function run(callable $callback): mixed
    {
        $context = $this->contextAccessor->current();

        if (null !== $context && $context->cancellationToken()->isCancellationRequested()) {
            throw new \RuntimeException(\sprintf('Tool execution "%s" cancelled before start.', $context->toolName()));
        }

        $result = $callback();

        if (null !== $context && $context->cancellationToken()->isCancellationRequested()) {
            throw new \RuntimeException(\sprintf('A result for tool "%s" was produced but is already stale due to run cancellation.', $context->toolName()));
        }

        return $result;
    }
}
