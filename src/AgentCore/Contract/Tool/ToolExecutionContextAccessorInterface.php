<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Contract\Tool;

/**
 * Ambient context accessor that lets tools retrieve the current execution
 * context during Symfony Toolbox invocation.
 *
 * Tool services inject this interface and call requireCurrent() to access
 * the active run/tool metadata and cancellation token without importing
 * AgentCore domain/infrastructure classes.
 */
interface ToolExecutionContextAccessorInterface
{
    /**
     * Returns the current execution context, or null if no tool execution
     * is active (e.g. outside of a ToolExecutor invocation).
     */
    public function current(): ?ToolExecutionContextInterface;

    /**
     * Returns the current execution context or throws when no tool
     * execution is active.
     *
     * @throws \LogicException
     */
    public function requireCurrent(): ToolExecutionContextInterface;

    /**
     * Pushes a context onto the stack, executes the callback, and pops
     * the context in all cases (success or exception).
     */
    public function with(ToolExecutionContextInterface $context, callable $callback): mixed;
}
