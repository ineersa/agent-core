<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tool;

use Ineersa\AgentCore\Application\Tool\StackToolExecutionContextAccessor;

/**
 * Shared runtime helper for tool authors providing cancellation checkpoints.
 *
 * {@see run()} checks cancellation before the callback and throws a clear
 * RuntimeException if cancelled so ToolExecutor converts it to a structured error.
 * Completed results are not rewritten here after the callback returns. Handlers
 * can call checkpoint() while waiting, but must not interrupt a file mutation.
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
     * Execute a callback with a pre-start cancellation checkpoint.
     *
     * Checks the ambient ToolContext cancellation token before the callback.
     * If cancelled before execution, throws immediately. Successful or typed
     * results returned by the callback are left intact; handlers that observe
     * cancellation during work must return or throw an explicit cancellation
     * outcome themselves.
     *
     * @param callable(): mixed $callback the tool logic to execute
     *
     * @return mixed the callback return value
     *
     * @throws \RuntimeException when cancellation is detected before the
     *                           callback. ToolExecutor catches this and
     *                           returns a structured error result.
     */
    public function run(callable $callback): mixed
    {
        $this->checkCancellation(' before start');

        return $callback();
    }

    /** Observe the current Fiber's tool cancellation while waiting for resources. */
    public function checkpoint(): void
    {
        $this->checkCancellation('');
    }

    private function checkCancellation(string $phase): void
    {
        $context = $this->contextAccessor->current();

        if (null !== $context && $context->cancellationToken()->isCancellationRequested()) {
            throw new \RuntimeException(\sprintf('Tool execution "%s" cancelled%s.', $context->toolName(), $phase));
        }
    }
}
